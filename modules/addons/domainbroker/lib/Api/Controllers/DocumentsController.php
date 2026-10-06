<?php
/**
 * Domain Broker — secure document endpoints.
 *
 * Downloads are streamed by the front controller from private storage; a
 * document's path is never exposed and never served from the web root.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Api\Controllers;

use DomainBroker\Api\ApiRequest;
use DomainBroker\Api\ApiResponse;
use DomainBroker\Api\Presenter;
use DomainBroker\Core\Actor;
use DomainBroker\Core\FileRejectedException;

class DocumentsController extends BaseController
{
    public function index(Actor $actor, ApiRequest $http)
    {
        $rows = $this->documentService()->listFor($actor, $http->intParam('id'));
        return ApiResponse::ok(Presenter::documents($rows));
    }

    public function store(Actor $actor, ApiRequest $http)
    {
        $file = null;
        if (isset($http->files['file'])) {
            $file = $http->files['file'];
        } elseif (isset($http->files['document'])) {
            $file = $http->files['document'];
        }
        if (!is_array($file)) {
            throw new FileRejectedException('No file was received.');
        }

        $document = $this->documentService()->upload($actor, $http->intParam('id'), $file, $http->body);
        return ApiResponse::created(Presenter::document($document));
    }

    /**
     * Metadata plus a one-time download descriptor. The bytes themselves are
     * served by api/download.php, which re-checks authorisation.
     */
    public function show(Actor $actor, ApiRequest $http)
    {
        $document = $this->documentService()->find($http->intParam('id'));
        if (!$document) {
            throw new \DomainBroker\Core\NotFoundException('Document not found.');
        }
        // Re-runs the full per-resource authorisation and audits the access.
        $this->documentService()->openForDownload($actor, $document);

        return ApiResponse::ok(array_merge(Presenter::document($document), [
            'download_url' => \DomainBroker\Core\Http::clientUrl([
                'm' => 'domainbroker',
                'action' => 'document',
                'uuid' => $document['uuid'],
            ]),
        ]));
    }
}
