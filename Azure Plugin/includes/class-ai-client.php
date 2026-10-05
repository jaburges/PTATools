<?php
/**
 * Azure AI Foundry client for the AI module: chat completions and
 * embeddings over the versionless /openai/v1/ API, with usage logging and
 * a monthly spending cap.
 *
 * Only published, public post content is ever sent.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_AI_Client {

    const MAX_RETRIES = 2;

    private $endpoint;
    private $api_key;
    private $chat_deployment;
    private $embed_deployment;

    public function __construct($settings = null) {
        $s = is_array($settings) ? $settings : Azure_AI_Module::settings();
        $this->endpoint = rtrim((string) ($s['ai_endpoint'] ?? ''), '/');
        $this->api_key = (string) ($s['ai_api_key'] ?? '');
        $this->chat_deployment = (string) ($s['ai_chat_deployment'] ?? 'chat');
        $this->embed_deployment = (string) ($s['ai_embed_deployment'] ?? 'embed');
    }

    public function is_configured() {
        return $this->endpoint !== '' && $this->api_key !== '';
    }

    /**
     * @param array  $messages OpenAI-style chat messages.
     * @param string $feature  Usage label, e.g. 'summary'.
     * @param array  $opts     json (bool), max_tokens (int), post_id (int),
     *                         timeout (seconds per attempt, default 60).
     * @return array|WP_Error  array('content' => string, 'usage' => array)
     */
    public function chat(array $messages, $feature, array $opts = array()) {
        $body = array(
            'model' => $this->chat_deployment,
            'messages' => $messages,
            'max_completion_tokens' => (int) ($opts['max_tokens'] ?? 1200),
            'reasoning_effort' => 'low',
        );
        if (!empty($opts['json'])) {
            $body['response_format'] = array('type' => 'json_object');
        }

        $data = $this->request('chat/completions', $body, $feature, $opts['post_id'] ?? 0, $this->chat_deployment, (int) ($opts['timeout'] ?? 60));
        if (is_wp_error($data)) {
            return $data;
        }

        $choice = $data['choices'][0] ?? array();
        $content = (string) ($choice['message']['content'] ?? '');
        if (($choice['finish_reason'] ?? '') === 'length') {
            return new WP_Error('pta_ai_truncated', 'The model ran out of output tokens.');
        }
        if ($content === '') {
            return new WP_Error('pta_ai_empty', 'The model returned no content.');
        }
        return array('content' => $content, 'usage' => $data['usage'] ?? array());
    }

    /**
     * @param string[] $inputs
     * @return array|WP_Error List of float vectors in input order.
     */
    public function embed(array $inputs, $feature = 'embedding', $post_id = 0) {
        $data = $this->request('embeddings', array(
            'model' => $this->embed_deployment,
            'input' => array_values($inputs),
            'dimensions' => Azure_AI_Vectors::DIMENSIONS,
        ), $feature, $post_id, $this->embed_deployment, 30);
        if (is_wp_error($data)) {
            return $data;
        }

        $vectors = array();
        foreach ((array) ($data['data'] ?? array()) as $row) {
            $vectors[(int) ($row['index'] ?? count($vectors))] = array_map('floatval', (array) ($row['embedding'] ?? array()));
        }
        ksort($vectors);
        if (count($vectors) !== count($inputs)) {
            return new WP_Error('pta_ai_embed_count', 'Embedding count does not match the input count.');
        }
        return array_values($vectors);
    }

    private function request($path, array $body, $feature, $post_id, $deployment, $timeout) {
        if (!$this->is_configured()) {
            return new WP_Error('pta_ai_not_configured', 'The AI endpoint and key are not set.');
        }
        if (Azure_AI_Store::over_budget()) {
            return new WP_Error('pta_ai_budget', 'The monthly AI budget has been reached.');
        }

        $url = $this->endpoint . '/openai/v1/' . $path;
        $attempt = 0;
        do {
            $response = wp_remote_post($url, array(
                'timeout' => max(5, (int) $timeout),
                'headers' => array(
                    'api-key' => $this->api_key,
                    'Content-Type' => 'application/json',
                ),
                'body' => wp_json_encode($body),
            ));

            if (is_wp_error($response)) {
                $error = $response;
                $retry = true;
                $wait = 2;
            } else {
                $code = (int) wp_remote_retrieve_response_code($response);
                $data = json_decode((string) wp_remote_retrieve_body($response), true);
                if ($code >= 200 && $code < 300 && is_array($data)) {
                    $usage = (array) ($data['usage'] ?? array());
                    Azure_AI_Store::record_usage(
                        $feature,
                        $deployment,
                        (int) ($usage['prompt_tokens'] ?? 0),
                        (int) ($usage['completion_tokens'] ?? 0),
                        (int) $post_id
                    );
                    return $data;
                }
                $message = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
                $error = new WP_Error('pta_ai_http_' . $code, trim('AI request failed (' . $code . '). ' . $message));
                $retry = $code === 429 || $code >= 500;
                $wait = max(1, min(20, (int) wp_remote_retrieve_header($response, 'retry-after')));
            }

            if (!$retry || $attempt >= self::MAX_RETRIES) {
                return $error;
            }
            $attempt++;
            sleep($wait);
        } while (true);
    }
}
