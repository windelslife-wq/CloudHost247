<?php
/**
 * Logo Studio persistence & export.
 *
 * Concepts are generated live by LogoEngine for the on-screen studio; a
 * project row is only written when the customer explicitly saves one. Exports
 * are SVG by design (infinitely scalable, editable in any vector tool);
 * PNG rendering is offered only when the Imagick extension is actually
 * present on the server, and the UI says so plainly when it is not.
 *
 * @package Chs\Services
 */

namespace Chs\Services;

use Chs\Core\ChsException;
use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\Logger;
use Chs\Core\NotFoundException;
use Chs\Core\RateLimiter;
use Chs\Core\ServiceUnavailableException;
use Chs\Core\Settings;
use Chs\Core\ValidationException;

class LogoService
{
    /** @var LogoEngine */
    private $engine;

    public function __construct(LogoEngine $engine = null)
    {
        $this->engine = $engine ?: new LogoEngine();
    }

    /** Library metadata for the studio's pickers. */
    public function libraries()
    {
        return [
            'palettes'   => LogoEngine::palettes(),
            'fonts'      => array_keys(LogoEngine::fontStacks()),
            'industries' => LogoEngine::industries(),
            'concepts'   => LogoEngine::conceptKeys(),
        ];
    }

    /**
     * Live preview: render every concept for the chosen direction. Nothing is
     * persisted here.
     *
     * @return array concept_key => svg
     */
    public function preview(array $opts)
    {
        $clean = $this->validate($opts, false);
        $out = [];
        foreach (LogoEngine::conceptKeys() as $key) {
            $out[$key] = $this->engine->render($key, $clean);
        }
        return $out;
    }

    /** Render one concept on demand (AJAX re-roll). */
    public function renderOne(array $opts, $concept)
    {
        if (!in_array($concept, LogoEngine::conceptKeys(), true)) {
            throw new ValidationException(['concept' => 'Unknown concept.']);
        }
        $clean = $this->validate($opts, false);
        return $this->engine->render($concept, $clean);
    }

    /**
     * Save the chosen concept as a project.
     */
    public function save($clientId, array $input)
    {
        if (!Settings::bool('logo_enabled', true)) {
            throw new ServiceUnavailableException('The Logo Studio is temporarily unavailable.');
        }
        RateLimiter::hitOrFail('logo_save', 'client:' . (int) $clientId, 40, 86400);

        $limit = Settings::int('logo_projects_limit', 25);
        if (Db::count('logo_projects', ['client_id' => (int) $clientId]) >= $limit) {
            throw new ChsException('You have reached the limit of ' . $limit
                . ' saved projects. Delete one to save another.');
        }

        $clean = $this->validate($input, true);
        $svg = $this->engine->render($clean['concept'], $clean);

        $id = Db::insert('logo_projects', [
            'client_id'    => (int) $clientId,
            'company_name' => $clean['company'],
            'industry'     => $clean['industry'],
            'style'        => $clean['style'],
            'palette'      => $clean['palette'],
            'layout'       => $clean['concept'],
            'concept_key'  => $clean['concept'],
            'icon'         => 'auto',
            'svg'          => $svg,
            'status'       => 'saved',
            'created_at'   => Clock::now(),
            'updated_at'   => Clock::now(),
        ]);

        \Chs\Core\Audit::client((int) $clientId, 'logo.saved', ['project' => $id]);
        return $this->getFor((int) $clientId, $id);
    }

    /** @return array[] */
    public function listFor($clientId)
    {
        $rows = Db::all('logo_projects', ['client_id' => (int) $clientId], 'id DESC', 100);
        foreach ($rows as &$row) {
            unset($row['svg']); // list view stays light
        }
        unset($row);
        return $rows;
    }

    public function getFor($clientId, $projectId)
    {
        $row = Db::first('logo_projects', ['id' => (int) $projectId, 'client_id' => (int) $clientId]);
        if (!$row) {
            throw new NotFoundException('Logo project not found.');
        }
        return $row;
    }

    public function delete($clientId, $projectId)
    {
        $row = $this->getFor($clientId, $projectId);
        Db::delete('logo_projects', ['id' => (int) $row['id'], 'client_id' => (int) $clientId]);
        \Chs\Core\Audit::client((int) $clientId, 'logo.deleted', ['project' => (int) $projectId]);
        return true;
    }

    /**
     * Export a saved project.
     *
     * @return array{mime:string, filename:string, data:string, format:string}
     */
    public function export($clientId, $projectId, $format = 'svg')
    {
        $row = $this->getFor($clientId, $projectId);
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($row['company_name']));
        $slug = trim($slug, '-') ?: 'logo';

        if ($format === 'svg') {
            return [
                'mime'     => 'image/svg+xml',
                'filename' => $slug . '-logo.svg',
                'data'     => $row['svg'],
                'format'   => 'svg',
            ];
        }
        if ($format === 'png') {
            if (!extension_loaded('imagick')) {
                throw new ServiceUnavailableException(
                    'PNG export needs the Imagick extension on the server, which is not installed here. '
                    . 'SVG download always works — every browser and tool opens it at any size.'
                );
            }
            try {
                $imagick = new \Imagick();
                $imagick->setBackgroundColor(new \ImagickPixel('transparent'));
                $imagick->readImageBlob($row['svg']);
                $imagick->setImageFormat('png');
                $imagick->resizeImage(1200, 0, \Imagick::FILTER_LANCZOS, 1);
                return [
                    'mime'     => 'image/png',
                    'filename' => $slug . '-logo.png',
                    'data'     => $imagick->getImageBlob(),
                    'format'   => 'png',
                ];
            } catch (\ImagickException $e) {
                // A broken/misconfigured Imagick runtime must degrade to the
                // honest service-unavailable state, never a 500.
                Logger::error('logo.png_failed', ['error' => $e->getMessage()]);
                throw new ServiceUnavailableException(
                    'PNG rendering failed on this host. SVG download always works — '
                    . 'every browser and design tool opens it at any size.'
                );
            }
        }
        throw new ValidationException(['format' => 'Supported formats: svg' . (extension_loaded('imagick') ? ', png' : '') . '.']);
    }

    /** Whether PNG export is available on this server (drives honest UI). */
    public function pngAvailable()
    {
        return extension_loaded('imagick');
    }

    protected function validate(array $input, $strict)
    {
        $errors = [];
        $company = trim(preg_replace('/\s+/u', ' ', (string) (isset($input['company']) ? $input['company'] : '')));
        $len = function_exists('mb_strlen') ? mb_strlen($company, 'UTF-8') : strlen($company);
        if ($strict && ($company === '' || $len > 40)) {
            $errors['company'] = 'Company name is required (up to 40 characters).';
        }
        if ($company === '') {
            $company = 'Your Company';
        }

        $industry = isset($input['industry']) ? (string) $input['industry'] : 'general';
        if (!isset(LogoEngine::industries()[$industry])) {
            $industry = 'general';
        }
        $style = isset($input['style']) ? (string) $input['style'] : 'modern';
        if (!array_key_exists($style, LogoEngine::fontStacks())) {
            $style = 'modern';
        }
        $palette = isset($input['palette']) ? (string) $input['palette'] : 'ocean';
        if (!isset(LogoEngine::palettes()[$palette])) {
            $palette = 'ocean';
        }
        $concept = isset($input['concept']) ? (string) $input['concept'] : 'wordmark';
        if (!in_array($concept, LogoEngine::conceptKeys(), true)) {
            $concept = 'wordmark';
        }

        if ($errors) {
            throw new ValidationException($errors);
        }
        return [
            'company'  => $company,
            'industry' => $industry,
            'style'    => $style,
            'palette'  => $palette,
            'concept'  => $concept,
        ];
    }
}
