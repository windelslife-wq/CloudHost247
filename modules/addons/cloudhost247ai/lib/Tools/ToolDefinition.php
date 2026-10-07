<?php
/**
 * Tool definition: declarative definitions, a registry that defaults to REFUSE,
 * and execution that always crosses identity → redaction → audit.
 *
 * Phase 1 registers READ tools only; the write-permission code paths exist
 * but no write tool is registered, so nothing can reach them.
 */

namespace Ch247Ai\Tools;

use Ch247Ai\Core\Ch247AiException;

class ToolDefinition
{
    /** @var string */
    public $name;
    /** @var string e.g. 'read.customers', 'write.billing' */
    public $permission;
    /** @var string risk class: READ | WRITE_LOW | WRITE_CUSTOMER_VISIBLE | WRITE_FINANCIAL | AVAILABILITY | SECURITY_ENFORCEMENT | MASS_COMMUNICATION */
    public $risk;
    /** @var string */
    public $description;
    /** @var array JSON-schema-ish parameter spec */
    public $params;
    /** @var callable array $args, array $ctx -> array result */
    public $fn;
    /** @var array ['entity' => 'whmcs_tblinvoices'] for the "no external state" rule */
    public $dataSource;
    /** @var bool may a client-scoped session call this tool (its reader MUST force client_id in SQL) */
    public $clientBound;

    /**
     * Post-write confirmation: function (array $args, array $ctx, array $result) : array
     * returning ['verified' => bool, 'note' => string]. Required for any tool
     * whose risk is not READ — a write with no way to confirm it landed may
     * not claim success (brief §29).
     *
     * @var callable|null
     */
    public $verify;

    public function __construct($name, $permission, $risk, $description, array $params, callable $fn, array $dataSource = [], $clientBound = false, callable $verify = null)
    {
        if (!preg_match('/^[a-z][a-z0-9_.]{2,60}$/', $name)) {
            throw new Ch247AiException('Invalid tool name: ' . $name);
        }
        $this->name = $name;
        $this->permission = $permission;
        $this->risk = $risk;
        $this->description = $description;
        $this->params = $params;
        $this->fn = $fn;
        $this->dataSource = $dataSource;
        $this->clientBound = (bool) $clientBound;
        $this->verify = $verify;
    }
    public function schema()
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => $this->schemaProperties(),
                    'required' => $this->schemaRequired(),
                ],
            ],
        ];
    }
    protected function schemaProperties()
    {
        $out = [];
        foreach ($this->params as $name => $spec) {
            $out[$name] = ['type' => isset($spec['type']) ? $spec['type'] : 'string'] + (isset($spec['description']) ? ['description' => $spec['description']] : []);
        }
        return $out;
    }
    protected function schemaRequired()
    {
        $out = [];
        foreach ($this->params as $name => $spec) {
            if (!isset($spec['required']) || $spec['required']) {
                $out[] = $name;
            }
        }
        return $out;
    }
}
