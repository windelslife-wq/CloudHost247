<?php
/**
 * Domain Broker — secure document storage.
 *
 * Documents (purchase agreements, ownership evidence, identity documents,
 * transfer paperwork, payment receipts, correspondence) are stored outside
 * any web-servable path under a randomised filename, and are only ever served
 * through an authorised controller action that re-checks per-resource
 * permission. The original filename is kept for display only and is never
 * used on disk.
 *
 * Uploads are validated by extension *and* by sniffed MIME type, size-capped,
 * and hashed. Anything that looks executable is rejected outright.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Services;

use DomainBroker\Core\Actor;
use DomainBroker\Core\Audit;
use DomainBroker\Core\AuthorizationException;
use DomainBroker\Core\Clock;
use DomainBroker\Core\Db;
use DomainBroker\Core\FileRejectedException;
use DomainBroker\Core\NotFoundException;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\RateLimiter;
use DomainBroker\Core\Settings;
use DomainBroker\Core\Str;
use DomainBroker\Core\ValidationException;

class DocumentService
{
    const CATEGORY_AGREEMENT    = 'purchase_agreement';
    const CATEGORY_OWNERSHIP    = 'ownership_verification';
    const CATEGORY_IDENTITY     = 'identity_verification';
    const CATEGORY_TRANSFER     = 'transfer_document';
    const CATEGORY_PAYMENT      = 'payment_document';
    const CATEGORY_CORRESPONDENCE = 'correspondence';
    const CATEGORY_OTHER        = 'other';

    const CATEGORIES = [
        self::CATEGORY_AGREEMENT      => 'Purchase agreement',
        self::CATEGORY_OWNERSHIP      => 'Ownership verification',
        self::CATEGORY_IDENTITY       => 'Identity verification',
        self::CATEGORY_TRANSFER       => 'Transfer document',
        self::CATEGORY_PAYMENT        => 'Payment document',
        self::CATEGORY_CORRESPONDENCE => 'Correspondence',
        self::CATEGORY_OTHER          => 'Other',
    ];

    const VIS_CUSTOMER = 'customer';
    const VIS_BROKER   = 'broker';
    const VIS_INTERNAL = 'internal';

    /** extension => allowed sniffed MIME types */
    const ALLOWED = [
        'pdf'  => ['application/pdf'],
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/plain', 'text/csv', 'application/csv'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls'  => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'odt'  => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'zip'  => ['application/zip'],
    ];

    /** Never accepted, whatever the sniffed type says. */
    const FORBIDDEN_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps',
        'exe', 'com', 'bat', 'cmd', 'sh', 'bash', 'cgi', 'pl', 'py', 'rb',
        'js', 'mjs', 'jsp', 'asp', 'aspx', 'htaccess', 'htpasswd', 'dll', 'so',
        'svg', 'html', 'htm', 'xhtml',
    ];

    /** @var RequestService */
    protected $requests;

    public function __construct(RequestService $requests = null)
    {
        $this->requests = $requests ?: new RequestService();
    }

    /* ----------------------------------------------------------- upload */

    /**
     * Accept an upload.
     *
     * @param array $file  a $_FILES entry: name, type, tmp_name, error, size
     * @param array $input category, visibility, description
     */
    public function upload(Actor $actor, $requestId, array $file, array $input = [])
    {
        Rbac::assert($actor, Rbac::DOCUMENT_UPLOAD);
        $request = $this->requests->findForActor($actor, $requestId);
        if ($actor->isBroker()) {
            $this->assertBrokerOwnsRequest($actor, $request);
        }
        RateLimiter::hit('document.upload', $actor->identity());

        $category = isset($input['category']) ? (string) $input['category'] : self::CATEGORY_OTHER;
        if (!isset(self::CATEGORIES[$category])) {
            throw new ValidationException('Unknown document category.', ['category' => 'Unknown category.']);
        }

        // A customer may only ever publish to the shared (customer) scope.
        $visibility = isset($input['visibility']) ? (string) $input['visibility'] : self::VIS_CUSTOMER;
        if (!in_array($visibility, [self::VIS_CUSTOMER, self::VIS_BROKER, self::VIS_INTERNAL], true)) {
            $visibility = self::VIS_CUSTOMER;
        }
        if ($actor->isCustomer()) {
            $visibility = self::VIS_CUSTOMER;
        }

        $meta = $this->validateUpload($file);

        $uuid = Str::uuid4();
        $storedName = bin2hex(random_bytes(20)) . '.' . $meta['extension'] . '.bin';
        $relativeDir = 'requests/' . date('Y/m', Clock::timestamp()) . '/' . (int) $request['id'];
        $absoluteDir = $this->storageRoot() . '/' . $relativeDir;
        $this->ensureDirectory($absoluteDir);

        $destination = $absoluteDir . '/' . $storedName;
        if (!$this->moveUploadedFile($meta['tmp_name'], $destination)) {
            throw new FileRejectedException('The uploaded file could not be stored.');
        }
        @chmod($destination, 0600);

        $now = Clock::now();
        $documentId = Db::insert('documents', [
            'uuid' => $uuid,
            'request_id' => (int) $request['id'],
            'category' => $category,
            'original_name' => Str::clip($meta['original_name'], 255),
            'stored_name' => $storedName,
            'storage_disk' => 'local',
            'storage_path' => $relativeDir . '/' . $storedName,
            'mime_type' => Str::clip($meta['mime'], 120),
            'extension' => $meta['extension'],
            'size_bytes' => (int) $meta['size'],
            'sha256' => $meta['sha256'],
            'visibility' => $visibility,
            'uploaded_by_type' => $actor->type,
            'uploaded_by_id' => (int) $actor->actorId(),
            'uploaded_by_label' => Str::clip($actor->name, 190),
            'scan_status' => $this->scanStatus(),
            'description' => Str::cleanText(isset($input['description']) ? $input['description'] : '', 1000) ?: null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Audit::record($actor, 'document.uploaded', [
            'request_id' => (int) $request['id'],
            'entity_type' => 'document',
            'entity_id' => $documentId,
            'new' => [
                'uuid' => $uuid,
                'category' => $category,
                'visibility' => $visibility,
                'name' => Str::clip($meta['original_name'], 190),
                'size_bytes' => (int) $meta['size'],
                'sha256' => $meta['sha256'],
            ],
            'visibility' => $visibility === self::VIS_CUSTOMER ? Audit::VIS_CUSTOMER : Audit::VIS_INTERNAL,
        ]);

        return $this->find($documentId);
    }

    /**
     * Validate an upload without touching the database.
     *
     * @return array{original_name:string, extension:string, mime:string, size:int, sha256:string, tmp_name:string}
     */
    public function validateUpload(array $file)
    {
        if (!isset($file['tmp_name']) || $file['tmp_name'] === '') {
            throw new FileRejectedException('No file was received.');
        }
        $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_OK;
        if ($error !== UPLOAD_ERR_OK) {
            throw new FileRejectedException($this->uploadErrorMessage($error));
        }
        if (!is_readable($file['tmp_name'])) {
            throw new FileRejectedException('The uploaded file could not be read.');
        }

        $size = isset($file['size']) ? (int) $file['size'] : (int) @filesize($file['tmp_name']);
        $maxBytes = max(65536, Settings::int('document_max_bytes', 15728640));
        if ($size <= 0) {
            throw new FileRejectedException('The uploaded file is empty.');
        }
        if ($size > $maxBytes) {
            throw new FileRejectedException(
                'The file exceeds the maximum upload size of '
                . round($maxBytes / 1048576, 1) . ' MB.'
            );
        }

        $originalName = isset($file['name']) ? (string) $file['name'] : 'upload';
        $originalName = str_replace(["\0", "\r", "\n", '/', '\\'], '', $originalName);
        $originalName = trim(basename($originalName));
        if ($originalName === '') {
            $originalName = 'upload';
        }

        // Reject double extensions outright: "invoice.php.pdf" is not a PDF.
        $parts = explode('.', strtolower($originalName));
        array_shift($parts);
        foreach ($parts as $part) {
            if (in_array($part, self::FORBIDDEN_EXTENSIONS, true)) {
                throw new FileRejectedException('That file type is not accepted.');
            }
        }

        $extension = count($parts) ? end($parts) : '';
        $extension = preg_replace('/[^a-z0-9]/', '', (string) $extension);
        if ($extension === '' || !isset(self::ALLOWED[$extension])) {
            throw new FileRejectedException(
                'Accepted file types: ' . implode(', ', array_keys(self::ALLOWED)) . '.'
            );
        }

        $mime = $this->sniffMime($file['tmp_name']);
        if ($mime !== '' && !in_array($mime, self::ALLOWED[$extension], true)) {
            throw new FileRejectedException('The file contents do not match its extension.');
        }

        // Defensive content check: no PHP open tag in anything we accept.
        $head = (string) @file_get_contents($file['tmp_name'], false, null, 0, 4096);
        if (stripos($head, '<?php') !== false || stripos($head, '<?=') !== false) {
            throw new FileRejectedException('The file contains executable content and was rejected.');
        }

        return [
            'original_name' => $originalName,
            'extension' => $extension,
            'mime' => $mime !== '' ? $mime : (isset(self::ALLOWED[$extension][0]) ? self::ALLOWED[$extension][0] : 'application/octet-stream'),
            'size' => $size,
            'sha256' => hash_file('sha256', $file['tmp_name']),
            'tmp_name' => $file['tmp_name'],
        ];
    }

    /* ------------------------------------------------------------- read */

    public function find($documentId)
    {
        return Db::first('documents', ['id' => (int) $documentId, 'deleted_at' => null]);
    }

    public function findByUuid($uuid)
    {
        return Db::first('documents', ['uuid' => (string) $uuid, 'deleted_at' => null]);
    }

    /** Documents on a request that this actor may see. */
    public function listFor(Actor $actor, $requestId)
    {
        $request = $this->requests->findForActor($actor, $requestId);

        $visibilities = [self::VIS_CUSTOMER];
        if (!$actor->isCustomer()) {
            if (Rbac::allows($actor, Rbac::NOTE_INTERNAL_READ)) {
                $visibilities[] = self::VIS_BROKER;
            }
            if (Rbac::allows($actor, Rbac::AUDIT_VIEW) || Rbac::allows($actor, Rbac::PII_VIEW)) {
                $visibilities[] = self::VIS_INTERNAL;
            }
        }

        return Db::fetch('documents', [
            'request_id' => (int) $request['id'],
            'deleted_at' => null,
            'visibility' => ['in', $visibilities],
        ], ['order' => 'id', 'dir' => 'desc']);
    }

    /**
     * Authorise and open a download. Returns the absolute path plus the
     * headers the controller should emit; the path itself is never exposed to
     * the browser.
     *
     * @return array{path:string, filename:string, mime:string, size:int}
     */
    public function openForDownload(Actor $actor, $documentId)
    {
        $document = is_array($documentId) ? $documentId : $this->find($documentId);
        if (!$document) {
            throw new NotFoundException('Document not found.');
        }

        // Re-check per-resource authorisation on every single download.
        $request = $this->requests->findForActor($actor, $document['request_id']);
        $this->assertCanRead($actor, $document, $request);

        if ($document['scan_status'] === 'infected') {
            throw new AuthorizationException('This document failed a security scan and cannot be downloaded.');
        }

        $path = $this->storageRoot() . '/' . $document['storage_path'];
        $real = realpath($path);
        $rootReal = realpath($this->storageRoot());
        if ($real === false || $rootReal === false || strpos($real, $rootReal) !== 0) {
            throw new NotFoundException('The stored file is no longer available.');
        }

        Audit::record($actor, 'document.downloaded', [
            'request_id' => (int) $document['request_id'],
            'entity_type' => 'document',
            'entity_id' => (int) $document['id'],
            'new' => ['uuid' => $document['uuid'], 'name' => $document['original_name']],
            'visibility' => Audit::VIS_INTERNAL,
        ]);

        return [
            'path' => $real,
            'filename' => $document['original_name'],
            'mime' => $document['mime_type'],
            'size' => (int) $document['size_bytes'],
        ];
    }

    public function assertCanRead(Actor $actor, array $document, array $request)
    {
        if ($actor->isCustomer()) {
            if ($document['visibility'] !== self::VIS_CUSTOMER) {
                // Do not disclose that an internal document exists.
                throw new NotFoundException('Document not found.');
            }
            return;
        }
        if ($actor->isBroker()) {
            if ((int) $request['assigned_broker_id'] !== (int) $actor->brokerId) {
                throw new AuthorizationException('This request is assigned to another broker.');
            }
            if ($document['visibility'] === self::VIS_INTERNAL && !Rbac::allows($actor, Rbac::AUDIT_VIEW)) {
                throw new AuthorizationException('You cannot access internal documents.');
            }
            return;
        }
        Rbac::assert($actor, Rbac::REQUEST_VIEW_ALL);
    }

    /* ----------------------------------------------------------- delete */

    /**
     * Soft-delete a document. The row and the file both remain — financial
     * and negotiation evidence is never destroyed — it simply stops being
     * listed and served.
     */
    public function softDelete(Actor $actor, $documentId, $reason)
    {
        $document = $this->find($documentId);
        if (!$document) {
            throw new NotFoundException('Document not found.');
        }
        $isUploader = $document['uploaded_by_type'] === $actor->type
            && (int) $document['uploaded_by_id'] === (int) $actor->actorId();
        if (!$isUploader) {
            Rbac::assert($actor, Rbac::STATUS_OVERRIDE);
        }
        $reason = Str::cleanText($reason, 1000);
        if ($reason === '') {
            throw new ValidationException('A reason is required.', ['reason' => 'Required.']);
        }

        Db::update('documents', [
            'deleted_at' => Clock::now(),
            'updated_at' => Clock::now(),
        ], ['id' => (int) $document['id']]);

        Audit::record($actor, 'document.removed', [
            'request_id' => (int) $document['request_id'],
            'entity_type' => 'document',
            'entity_id' => (int) $document['id'],
            'previous' => ['name' => $document['original_name'], 'visibility' => $document['visibility']],
            'new' => ['deleted' => true],
            'reason' => $reason,
            'visibility' => Audit::VIS_INTERNAL,
        ]);

        return true;
    }

    /** Record the outcome of an external malware scan. */
    public function recordScanResult(Actor $actor, $documentId, $status, $detail = '')
    {
        if (!in_array($status, ['clean', 'infected', 'skipped', 'error'], true)) {
            throw new ValidationException('Unknown scan status.', ['status' => 'Unknown status.']);
        }
        $document = $this->find($documentId);
        if (!$document) {
            throw new NotFoundException('Document not found.');
        }

        Db::update('documents', [
            'scan_status' => $status,
            'scan_result' => Str::clip($detail, 1000) ?: null,
            'scanned_at' => Clock::now(),
            'updated_at' => Clock::now(),
        ], ['id' => (int) $document['id']]);

        Audit::record($actor, 'document.scanned', [
            'request_id' => (int) $document['request_id'],
            'entity_type' => 'document',
            'entity_id' => (int) $document['id'],
            'new' => ['scan_status' => $status],
            'visibility' => Audit::VIS_INTERNAL,
        ]);

        return $this->find($documentId);
    }

    /* ---------------------------------------------------------- storage */

    /** Absolute path of the private storage root. */
    public function storageRoot()
    {
        $configured = Settings::string('document_storage_path', '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        $base = defined('DOMAINBROKER_ROOT') ? DOMAINBROKER_ROOT : dirname(dirname(__DIR__));
        return rtrim($base, '/') . '/storage/documents';
    }

    /**
     * Create the storage directory and make it inert: deny-all for Apache,
     * an index stub for anything else, and no directory listing.
     */
    public function ensureDirectory($dir)
    {
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new FileRejectedException('The document storage directory could not be created.');
        }
        $root = $this->storageRoot();
        if (!is_dir($root)) {
            return;
        }
        $htaccess = $root . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, implode("\n", [
                '# Domain Broker private document store — never serve these files directly.',
                '<IfModule mod_authz_core.c>',
                '    Require all denied',
                '</IfModule>',
                '<IfModule !mod_authz_core.c>',
                '    Order deny,allow',
                '    Deny from all',
                '</IfModule>',
                'Options -Indexes -ExecCGI',
                'php_flag engine off',
                '',
            ]));
        }
        $index = $root . '/index.html';
        if (!file_exists($index)) {
            @file_put_contents($index, '');
        }
    }

    /** Overridable for tests (move_uploaded_file only works on real uploads). */
    protected function moveUploadedFile($source, $destination)
    {
        if (is_uploaded_file($source)) {
            return move_uploaded_file($source, $destination);
        }
        // CLI / test path: a plain rename is correct and still confined to the
        // storage root because $destination is built, never user-supplied.
        return @rename($source, $destination) || @copy($source, $destination);
    }

    protected function sniffMime($path)
    {
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = @finfo_file($finfo, $path);
                @finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return strtolower($mime);
                }
            }
        }
        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($path);
            if (is_string($mime) && $mime !== '') {
                return strtolower($mime);
            }
        }
        return '';
    }

    protected function scanStatus()
    {
        return Settings::bool('virus_scan_enabled', false) ? 'pending' : 'skipped';
    }

    protected function uploadErrorMessage($code)
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'The file is larger than the server allows.';
            case UPLOAD_ERR_PARTIAL:
                return 'The upload was interrupted. Please try again.';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was selected.';
            case UPLOAD_ERR_NO_TMP_DIR:
            case UPLOAD_ERR_CANT_WRITE:
                return 'The server could not store the upload.';
            case UPLOAD_ERR_EXTENSION:
                return 'The upload was blocked by the server configuration.';
            default:
                return 'The upload failed.';
        }
    }

    protected function assertBrokerOwnsRequest(Actor $actor, array $request)
    {
        if ((int) $request['assigned_broker_id'] !== (int) $actor->brokerId) {
            throw new AuthorizationException('This request is assigned to another broker.');
        }
    }
}
