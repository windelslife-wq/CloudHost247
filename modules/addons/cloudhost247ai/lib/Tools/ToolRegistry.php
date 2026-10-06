<?php
/** Tool registry: registration, disable flags, scope filtering. */

namespace Ch247Ai\Tools;

use Ch247Ai\Core\Ch247AiException;

class ToolRegistry
{
    /** @var ToolDefinition[] */
    private static $tools = [];
    /** @var array<string,bool> */
    private static $disabled = [];

    public static function register(ToolDefinition $tool)
    {
        self::$tools[$tool->name] = $tool;
    }
    public static function registerAll(array $tools)
    {
        foreach ($tools as $tool) {
            self::register($tool);
        }
    }
    public static function setDisabled($name, $disabled)
    {
        self::$disabled[(string) $name] = (bool) $disabled;
    }
    public static function reset()
    {
        self::$tools = [];
        self::$disabled = [];
    }
    public static function all()
    {
        return self::$tools;
    }
    public static function enabledFor($scope)
    {
        $out = [];
        foreach (self::$tools as $tool) {
            if ($tool->risk !== 'READ') {
                continue;
            }
            if (!empty(self::$disabled[$tool->name])) {
                continue;
            }
            if ($scope === 'client' && !$tool->clientBound) {
                continue;
            }
            $out[] = $tool;
        }
        return $out;
    }
    /** @return ToolDefinition|null */
    public static function find($name)
    {
        return isset(self::$tools[$name]) ? self::$tools[$name] : null;
    }
    public static function isDisabled($name)
    {
        return !empty(self::$disabled[(string) $name]);
    }
}
