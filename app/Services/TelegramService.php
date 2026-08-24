<?php

namespace App\Services;

use App\Core\Config;
use App\Helpers\Logger;
use CURLFile;

/**
 * Thin wrapper around the Telegram Bot API. Every public method maps
 * to one Bot API method; all requests go through request().
 */
class TelegramService
{
    private string $apiUrl;

    public function __construct()
    {
        $token = Config::get('bot_token');
        $this->apiUrl = "https://api.telegram.org/bot{$token}/";
    }

    private function request(string $method, array $params = [], bool $multipart = false): ?array
    {
        $ch = curl_init($this->apiUrl . $method);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $multipart ? $params : http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            Logger::write('error', 'Telegram API cURL error: ' . curl_error($ch), ['method' => $method]);
            curl_close($ch);
            return null;
        }
        curl_close($ch);

        $data = json_decode($response, true);
        if (!is_array($data) || ($data['ok'] ?? false) !== true) {
            Logger::write('error', 'Telegram API returned an error', ['method' => $method, 'response' => $data]);
        }
        return is_array($data) ? $data : null;
    }

    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null, string $parseMode = 'Markdown'): ?array
    {
        $params = [
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => $parseMode,
            'disable_web_page_preview' => true,
        ];
        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        return $this->request('sendMessage', $params);
    }

    public function sendVideo(int $chatId, string $videoUrl, string $caption = '', ?array $replyMarkup = null): ?array
    {
        $params = [
            'chat_id'             => $chatId,
            'video'               => $videoUrl,
            'caption'             => $caption,
            'parse_mode'          => 'Markdown',
            'supports_streaming'  => true,
        ];
        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        return $this->request('sendVideo', $params);
    }

    /** Uploads a local file via multipart/form-data (for videos over the URL-fetch size limit). */
    public function sendVideoLocal(int $chatId, string $filePath, string $caption = '', ?array $replyMarkup = null): ?array
    {
        $params = [
            'chat_id'            => $chatId,
            'video'              => new CURLFile($filePath),
            'caption'            => $caption,
            'parse_mode'         => 'Markdown',
            'supports_streaming' => true,
        ];
        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        return $this->request('sendVideo', $params, true);
    }

    public function sendPhoto(int $chatId, string $photoUrl, string $caption = ''): ?array
    {
        return $this->request('sendPhoto', [
            'chat_id'    => $chatId,
            'photo'      => $photoUrl,
            'caption'    => $caption,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * @param string[] $imageUrls
     * Telegram allows at most 10 items per media group, so larger
     * TikTok photo carousels are split and sent as consecutive groups.
     */
    public function sendMediaGroup(int $chatId, array $imageUrls): ?array
    {
        $result = null;
        foreach (array_chunk($imageUrls, 10) as $chunk) {
            $media = array_map(fn($url) => ['type' => 'photo', 'media' => $url], $chunk);
            $result = $this->request('sendMediaGroup', [
                'chat_id' => $chatId,
                'media'   => json_encode($media),
            ]);
        }
        return $result;
    }

    public function sendAudio(int $chatId, string $audioUrl, string $caption = '', ?string $thumbUrl = null, ?array $replyMarkup = null): ?array
    {
        $params = [
            'chat_id'    => $chatId,
            'audio'      => $audioUrl,
            'caption'    => $caption,
            'parse_mode' => 'Markdown',
        ];
        if ($thumbUrl) {
            $params['thumbnail'] = $thumbUrl;
        }
        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        return $this->request('sendAudio', $params);
    }

    /** $action: typing | upload_video | upload_photo | upload_audio | ... */
    public function sendChatAction(int $chatId, string $action): ?array
    {
        return $this->request('sendChatAction', ['chat_id' => $chatId, 'action' => $action]);
    }

    /**
     * Copies an existing message (any content type) into $chatId —
     * used to replay stored ads from the admin's chat and to broadcast
     * a forwarded message to all users. The source message itself is
     * not quoted or attributed.
     */
    public function copyMessage(int $chatId, int $fromChatId, int $messageId): ?array
    {
        return $this->request('copyMessage', [
            'chat_id'      => $chatId,
            'from_chat_id' => $fromChatId,
            'message_id'   => $messageId,
        ]);
    }

    public function editMessageText(int $chatId, int $messageId, string $text, ?array $replyMarkup = null, string $parseMode = 'Markdown'): ?array
    {
        $params = [
            'chat_id'                  => $chatId,
            'message_id'               => $messageId,
            'text'                     => $text,
            'parse_mode'               => $parseMode,
            'disable_web_page_preview' => true,
        ];
        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        return $this->request('editMessageText', $params);
    }

    /** Pass null to clear the keyboard entirely. */
    public function editMessageReplyMarkup(int $chatId, int $messageId, ?array $replyMarkup): ?array
    {
        return $this->request('editMessageReplyMarkup', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'reply_markup' => json_encode($replyMarkup ?? ['inline_keyboard' => []]),
        ]);
    }

    public function deleteMessage(int $chatId, int $messageId): ?array
    {
        return $this->request('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
    }

    public function answerCallbackQuery(string $callbackId, string $text = '', bool $showAlert = false): ?array
    {
        return $this->request('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text'              => $text,
            'show_alert'        => $showAlert,
        ]);
    }

    /** $channel like "@channelusername" */
    public function getChatMember(string $channel, int $userId): ?array
    {
        return $this->request('getChatMember', ['chat_id' => $channel, 'user_id' => $userId]);
    }

    public function setWebhook(string $url, ?string $secret = null): ?array
    {
        $params = ['url' => $url];
        if ($secret) {
            $params['secret_token'] = $secret;
        }
        return $this->request('setWebhook', $params);
    }
}
