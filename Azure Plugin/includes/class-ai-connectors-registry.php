<?php
/**
 * AI Connectors tool registry.
 *
 * Other modules add tools from the `pta_ai_connectors_register_tools` action:
 *
 *     add_action('pta_ai_connectors_register_tools', function () {
 *         Azure_AI_Connectors_Registry::register('pta_list_forms', array(
 *             'title'        => 'List forms',
 *             'description'  => 'Forms built with PTA Tools Forms.',
 *             'kind'         => Azure_AI_Connectors_Policy::KIND_READ,
 *             'permission'   => array('access_pta_tools'),
 *             'input_schema' => array('type' => 'object', 'properties' => array()),
 *             'callback'     => function (array $args, array $context) { return array('forms' => array()); },
 *         ));
 *     });
 *
 * Definition keys:
 *   title, description   Shown to the assistant and on the consent screen.
 *   kind                 read | create | update | destructive | admin.
 *   permission           Capability or list of capabilities; any one passes.
 *   permission_callback  Optional extra check, run after the capability check.
 *   available            Optional callable; false hides the tool (module off).
 *   requires_confirm     Force confirm=true on a non-destructive tool.
 *   input_schema         JSON Schema object for the arguments.
 *   callback             fn(array $args, array $context): array|WP_Error.
 *   group                Heading on the setup page (site, content, ...).
 *   open_world           True when the tool reaches an outside service.
 *
 * The callback runs as the signed-in user and must still do any per-object
 * capability checks (edit_post on a given id, for example).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_AI_Connectors_Registry {

    /** @var array<string,array> */
    private static $tools = array();

    /** @var bool */
    private static $booted = false;

    /**
     * @return true|WP_Error
     */
    public static function register($name, array $def) {
        $name = (string) $name;
        if (!preg_match('/^[a-z][a-z0-9_]{2,63}$/', $name)) {
            return new WP_Error('pta_ai_bad_tool_name', 'Tool names are lowercase letters, digits and underscores.');
        }
        if (empty($def['callback']) || !is_callable($def['callback'])) {
            return new WP_Error('pta_ai_bad_tool', "Tool {$name} needs a callable callback.");
        }
        $kind = isset($def['kind']) ? (string) $def['kind'] : Azure_AI_Connectors_Policy::KIND_READ;
        if (!in_array($kind, Azure_AI_Connectors_Policy::kinds(), true)) {
            return new WP_Error('pta_ai_bad_tool', "Tool {$name} has an unknown kind.");
        }

        $schema = isset($def['input_schema']) && is_array($def['input_schema']) ? $def['input_schema'] : array();
        $schema['type'] = 'object';
        if (!isset($schema['properties']) || !is_array($schema['properties'])) {
            $schema['properties'] = array();
        }

        $def['name'] = $name;
        $def['kind'] = $kind;
        $def['title'] = isset($def['title']) ? (string) $def['title'] : $name;
        $def['description'] = isset($def['description']) ? (string) $def['description'] : '';
        $def['group'] = isset($def['group']) ? (string) $def['group'] : 'other';

        if (Azure_AI_Connectors_Policy::requires_confirm($def)) {
            $schema['properties']['confirm'] = array(
                'type'        => 'boolean',
                'description' => 'Must be true. Only set it after the user has explicitly confirmed this change in the conversation.',
            );
            $required = isset($schema['required']) ? (array) $schema['required'] : array();
            if (!in_array('confirm', $required, true)) {
                $required[] = 'confirm';
            }
            $schema['required'] = $required;
            if (strpos($def['description'], 'confirm=true') === false) {
                $def['description'] = rtrim($def['description']) . ' DESTRUCTIVE: requires confirm=true, which you may only send after the user explicitly confirms.';
            }
        }

        if (empty($schema['properties'])) {
            $schema['properties'] = new stdClass();
        }
        $def['input_schema'] = $schema;

        self::$tools[$name] = $def;
        return true;
    }

    public static function unregister($name) {
        unset(self::$tools[(string) $name]);
    }

    /** @return array<string,array> */
    public static function all() {
        self::boot();
        return self::$tools;
    }

    public static function get($name) {
        self::boot();
        return self::$tools[(string) $name] ?? null;
    }

    /**
     * Tools the current user can use over a connection with these scopes.
     *
     * @return array<string,array>
     */
    public static function available(array $scopes) {
        $out = array();
        foreach (self::all() as $name => $tool) {
            if (Azure_AI_Connectors_Policy::check($tool, $scopes) === true) {
                $out[$name] = $tool;
            }
        }
        return $out;
    }

    /** MCP tools/list entry. */
    public static function to_mcp(array $tool) {
        $kind = $tool['kind'];
        return array(
            'name'        => $tool['name'],
            'title'       => $tool['title'],
            'description' => $tool['description'],
            'inputSchema' => $tool['input_schema'],
            'annotations' => array(
                'title'           => $tool['title'],
                'readOnlyHint'    => $kind === Azure_AI_Connectors_Policy::KIND_READ,
                'destructiveHint' => Azure_AI_Connectors_Policy::requires_confirm($tool),
                'idempotentHint'  => in_array($kind, array(Azure_AI_Connectors_Policy::KIND_READ, Azure_AI_Connectors_Policy::KIND_UPDATE), true),
                'openWorldHint'   => !empty($tool['open_world']),
            ),
        );
    }

    /**
     * Check arguments against the subset of JSON Schema the tools use:
     * required, type, enum, minimum/maximum, maxLength, array items type.
     * Unknown properties are dropped.
     *
     * @return array|WP_Error Cleaned arguments.
     */
    public static function validate_args(array $schema, $args) {
        if ($args === null) {
            $args = array();
        }
        if (!is_array($args) || ($args !== array() && array_keys($args) === range(0, count($args) - 1))) {
            return new WP_Error('pta_ai_bad_args', 'Arguments must be a JSON object.');
        }
        $props = isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : array();
        foreach ((array) ($schema['required'] ?? array()) as $req) {
            if (!array_key_exists($req, $args)) {
                return new WP_Error('pta_ai_bad_args', "Missing required argument: {$req}.");
            }
        }
        $clean = array();
        foreach ($props as $key => $prop) {
            if (!array_key_exists($key, $args)) {
                continue;
            }
            $value = $args[$key];
            $error = self::check_value($key, $value, (array) $prop);
            if ($error !== null) {
                return new WP_Error('pta_ai_bad_args', $error);
            }
            $clean[$key] = $value;
        }
        return $clean;
    }

    /**
     * @param mixed $value Passed by reference so integers sent as "5" or 5.0
     *                     are normalised.
     * @return string|null Error message.
     */
    private static function check_value($key, &$value, array $prop) {
        $type = $prop['type'] ?? null;
        $types = (array) $type;
        if ($type !== null) {
            $ok = false;
            foreach ($types as $t) {
                if (self::is_type($value, $t)) {
                    $ok = true;
                    if ($t === 'integer') {
                        $value = (int) $value;
                    } elseif ($t === 'number') {
                        $value = $value + 0;
                    }
                    break;
                }
            }
            if (!$ok) {
                return "Argument {$key} must be " . implode(' or ', $types) . '.';
            }
        }
        if (isset($prop['enum']) && !in_array($value, (array) $prop['enum'], true)) {
            return "Argument {$key} must be one of: " . implode(', ', array_map('strval', (array) $prop['enum'])) . '.';
        }
        if (is_int($value) || is_float($value)) {
            if (isset($prop['minimum']) && $value < $prop['minimum']) {
                return "Argument {$key} must be at least {$prop['minimum']}.";
            }
            if (isset($prop['maximum']) && $value > $prop['maximum']) {
                return "Argument {$key} must be at most {$prop['maximum']}.";
            }
        }
        if (is_string($value) && isset($prop['maxLength']) && strlen($value) > (int) $prop['maxLength']) {
            return "Argument {$key} is too long (max {$prop['maxLength']} characters).";
        }
        if (is_array($value) && isset($prop['items']['type'])) {
            if (isset($prop['maxItems']) && count($value) > (int) $prop['maxItems']) {
                return "Argument {$key} has too many items (max {$prop['maxItems']}).";
            }
            foreach ($value as $i => $item) {
                if (!self::is_type($item, $prop['items']['type'])) {
                    return "Argument {$key}[{$i}] must be {$prop['items']['type']}.";
                }
                if ($prop['items']['type'] === 'integer') {
                    $value[$i] = (int) $item;
                }
            }
        }
        return null;
    }

    private static function is_type($value, $type) {
        switch ($type) {
            case 'string':
                return is_string($value);
            case 'integer':
                if (is_int($value)) {
                    return true;
                }
                if (is_float($value)) {
                    return floor($value) === $value;
                }
                return is_string($value) && preg_match('/^-?\d+$/', $value) === 1;
            case 'number':
                return is_int($value) || is_float($value);
            case 'boolean':
                return is_bool($value);
            case 'array':
                return is_array($value) && ($value === array() || array_keys($value) === range(0, count($value) - 1));
            case 'object':
                return is_array($value) || is_object($value);
            case 'null':
                return $value === null;
        }
        return false;
    }

    private static function boot() {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        if (class_exists('Azure_AI_Connectors_Tools')) {
            Azure_AI_Connectors_Tools::register_defaults();
        }
        do_action('pta_ai_connectors_register_tools');
    }

    /** Tests only. */
    public static function reset($booted = false) {
        self::$tools = array();
        self::$booted = $booted;
    }
}
