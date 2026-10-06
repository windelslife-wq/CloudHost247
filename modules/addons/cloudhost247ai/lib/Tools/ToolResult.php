<?php
/** Tool result envelope: data + SQL-shaped citations. */

namespace Ch247Ai\Tools;

class ToolResult
{
    /** @var array data handed back to the model / run record */
    public $data;
    /** @var string[] SQL-shaped citations backing this result */
    public $citations;

    public function __construct(array $data, array $citations)
    {
        $this->data = $data;
        $this->citations = $citations;
    }
}
