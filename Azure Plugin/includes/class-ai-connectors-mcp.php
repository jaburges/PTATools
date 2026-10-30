<?php
/**
 * AI Connectors MCP server: JSON-RPC 2.0 over the Streamable HTTP transport.
 *
 * Stateless: no Mcp-Session-Id is issued and every POST carries its own
 * bearer token, so any PHP worker can answer any request. Responses are
 * always a single application/json body (no SSE stream); the transport
 * allows that.
 *
 * The HTTP layer (Azure_AI_Connectors_Module::rest_mcp) authenticates the
 * token and calls wp_set_current_user() before handing the payload here.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_AI_Connectors_MCP {

    /** Newest first. */
    const PROTOCOL_VERSIONS = array('2025-11-25', '2025-06-18', '2025-03-26');

    const PARSE_ERROR      = -32700;
    const INVALID_REQUEST  = -32600;
    const METHOD_NOT_FOUND = -32601;
    const INVALID_PARAMS   = -32602;
    const INTERNAL_ERROR   = -32603;

    /**
     * @param mixed $payload Decoded JSON body.
     * @param array $context user (WP_User), token (row), scopes (string[]), client_label (string)
     * @return array|null Response body, or null when nothing needs answering
     *                    (notifications and client responses only → HTTP 202).
     */
    public static function handle_payload($payload, array $context) {
        if (!is_array($payload) || $payload === array()) {
            return self::error(null, self::INVALID_REQUEST, 'Invalid request.');
        }
        if (array_keys($payload) === range(0, count($payload) - 1)) {
            $responses = array();
            foreach ($payload as $message) {
                $response = is_array($message)
                    ? self::handle_message($message, $context)
                    : self::error(null, self::INVALID_REQUEST, 'Invalid request.');
                if ($response !== null) {
                    $responses[] = $response;
                }
            }
            return $responses ? $responses : null;
        }
        return self::handle_message($payload, $context);
    }

    public static function handle_message(array $message, array $context) {
        $has_id = array_key_exists('id', $message);
        $id = $has_id ? $message['id'] : null;

        if (($message['jsonrpc'] ?? null) !== '2.0') {
            return self::error($id, self::INVALID_REQUEST, 'jsonrpc must be "2.0".');
        }
        if (!isset($message['method'])) {
            // A response to a server→client request; this server sends none.
            return null;
        }
        if (!is_string($message['method'])) {
            return self::error($id, self::INVALID_REQUEST, 'method must be a string.');
        }
        if ($has_id && !is_string($id) && !is_int($id)) {
            return self::error(null, self::INVALID_REQUEST, 'id must be a string or integer.');
        }
        $params = isset($message['params']) && is_array($message['params']) ? $message['params'] : array();
        $method = $message['method'];

        if (!$has_id) {
            return null;
        }

        switch ($method) {
            case 'initialize':
                return self::result($id, self::initialize($params));
            case 'ping':
                return self::result($id, new stdClass());
            case 'tools/list':
                return self::result($id, self::tools_list($context));
            case 'tools/call':
                return self::tools_call($id, $params, $context);
        }
        return self::error($id, self::METHOD_NOT_FOUND, 'Method not found: ' . $method);
    }

    public static function negotiate_version($requested) {
        return in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0];
    }

    private static function initialize(array $params) {
        $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        return array(
            'protocolVersion' => self::negotiate_version((string) ($params['protocolVersion'] ?? '')),
            'capabilities'    => array(
                'tools' => array('listChanged' => false),
            ),
            'serverInfo'      => array(
                'name'    => 'pta-tools',
                'title'   => trim($site . ' PTA Tools'),
                'version' => defined('AZURE_PLUGIN_VERSION') ? AZURE_PLUGIN_VERSION : '0',
            ),
            'instructions'    => self::instructions(),
        );
    }

    public static function instructions() {
        return 'Tools act on this WordPress site as the signed-in user, with exactly that user\'s permissions. '
            . 'Call pta_whoami first to see who you are acting as and what you can do. '
            . 'Tools marked DESTRUCTIVE need confirm=true: describe the change to the user and only send confirm=true after they explicitly agree. '
            . 'Deleting moves items to the trash; nothing is permanently deleted.';
    }

    private static function tools_list(array $context) {
        $tools = array();
        foreach (Azure_AI_Connectors_Registry::available((array) $context['scopes']) as $tool) {
            $tools[] = Azure_AI_Connectors_Registry::to_mcp($tool);
        }
        return array('tools' => $tools);
    }

    private static function tools_call($id, array $params, array $context) {
        $name = isset($params['name']) && is_string($params['name']) ? $params['name'] : '';
        $raw_args = $params['arguments'] ?? array();
        if (is_object($raw_args)) {
            $raw_args = (array) $raw_args;
        }
        $tool = $name !== '' ? Azure_AI_Connectors_Registry::get($name) : null;
        if (!$tool) {
            self::audit($context, $name, $raw_args, Azure_AI_Connectors_Audit::STATUS_INVALID, 'Unknown tool', 0);
            return self::error($id, self::INVALID_PARAMS, 'Unknown tool: ' . $name);
        }

        $started = microtime(true);
        $allowed = Azure_AI_Connectors_Policy::check($tool, (array) $context['scopes']);
        if (is_wp_error($allowed)) {
            self::audit($context, $name, $raw_args, Azure_AI_Connectors_Audit::STATUS_DENIED, $allowed->get_error_code(), 0);
            return self::result($id, self::tool_error($allowed->get_error_message()));
        }

        if (Azure_AI_Connectors_Policy::requires_confirm($tool) && !Azure_AI_Connectors_Policy::confirmed(is_array($raw_args) ? $raw_args : array())) {
            self::audit($context, $name, $raw_args, Azure_AI_Connectors_Audit::STATUS_CONFIRM, 'confirm=true missing', 0);
            return self::result($id, self::tool_error(
                'Not done. "' . $tool['title'] . '" changes or removes data, so it needs confirm=true. '
                . 'Tell the user exactly what will change, ask them to confirm, and only then call it again with confirm=true.'
            ));
        }

        $args = Azure_AI_Connectors_Registry::validate_args($tool['input_schema'], $raw_args);
        if (is_wp_error($args)) {
            self::audit($context, $name, $raw_args, Azure_AI_Connectors_Audit::STATUS_INVALID, $args->get_error_message(), 0);
            return self::result($id, self::tool_error($args->get_error_message()));
        }

        try {
            $data = call_user_func($tool['callback'], $args, $context);
        } catch (\Throwable $e) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::error('AI Connectors: tool ' . $name . ' threw ' . get_class($e) . ': ' . $e->getMessage(), array('module' => 'AIConnectors'));
            }
            $data = new WP_Error('pta_ai_tool_failed', 'The tool failed unexpectedly. The site log has details.');
        }
        $ms = (int) round((microtime(true) - $started) * 1000);

        if (is_wp_error($data)) {
            self::audit($context, $name, $args, Azure_AI_Connectors_Audit::STATUS_ERROR, $data->get_error_message(), $ms);
            return self::result($id, self::tool_error($data->get_error_message()));
        }

        self::audit($context, $name, $args, Azure_AI_Connectors_Audit::STATUS_OK, '', $ms);
        if (!is_array($data) || ($data !== array() && array_keys($data) === range(0, count($data) - 1))) {
            $data = array('items' => $data);
        }
        return self::result($id, array(
            'content'           => array(array(
                'type' => 'text',
                'text' => (string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            )),
            'structuredContent' => $data === array() ? new stdClass() : $data,
            'isError'           => false,
        ));
    }

    private static function tool_error($message) {
        return array(
            'content' => array(array('type' => 'text', 'text' => (string) $message)),
            'isError' => true,
        );
    }

    private static function audit(array $context, $tool, $args, $status, $message, $ms) {
        if (!class_exists('Azure_AI_Connectors_Audit')) {
            return;
        }
        Azure_AI_Connectors_Audit::log(array(
            'user_id'      => isset($context['user']->ID) ? (int) $context['user']->ID : 0,
            'token_id'     => isset($context['token']->id) ? (int) $context['token']->id : 0,
            'client_label' => (string) ($context['client_label'] ?? ''),
            'tool'         => (string) $tool,
            'args'         => $args,
            'status'       => $status,
            'message'      => (string) $message,
            'duration_ms'  => (int) $ms,
        ));
    }

    private static function result($id, $result) {
        return array('jsonrpc' => '2.0', 'id' => $id, 'result' => $result);
    }

    public static function error($id, $code, $message) {
        return array('jsonrpc' => '2.0', 'id' => $id, 'error' => array('code' => (int) $code, 'message' => (string) $message));
    }
}
