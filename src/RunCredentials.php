<?php

declare(strict_types=1);

namespace skeeks\cms\ai\service;

use skeeks\cms\oauth2\models\CmsOauthAccessToken;
use skeeks\cms\oauth2\models\CmsOauthClient;
use Yii;
use yii\helpers\Json;

/**
 * Short-lived REST credentials of one run, issued on this installation for the
 * authenticated employee. They replace the interactive OAuth sign-in of the
 * pilot service: the executor receives a token bound to the REST resource and
 * the employee, the target API still checks the employee's current rights.
 */
final class RunCredentials
{
    public const CLIENT_ID = 'skeeks-ai-service';
    public const LIFETIME = 3600;

    /** Issues a token in a web request of the employee; the resource URI needs the request host. */
    public static function issue(int $userId): string
    {
        // The executor calls the REST API, not the first (MCP) resource of the OAuth server.
        $rest = rtrim(\yii\helpers\Url::to(Yii::$app->cmsMcp->restResourceRoute, true), '/');
        $resource = Yii::$app->oauth2Server->getResource($rest);
        $plain = Yii::$app->security->generateRandomString(64);
        $token = new CmsOauthAccessToken([
            'created_at' => time(),
            'client_id' => self::client()->id,
            'cms_user_id' => $userId,
            'token_hash' => CmsOauthAccessToken::hashToken($plain),
            'scopes' => Json::encode(array_values($resource['scopes'])),
            'resource' => $resource['uri'],
            'expires_at' => time() + self::LIFETIME,
        ]);
        if (!$token->save()) {
            throw new \RuntimeException('Не удалось выдать доступ для поручения.');
        }
        return $plain;
    }

    public static function revoke(string $plain): void
    {
        CmsOauthAccessToken::updateAll(['revoked_at' => time()], [
            'token_hash' => CmsOauthAccessToken::hashToken($plain), 'revoked_at' => null,
        ]);
    }

    public static function encrypt(string $plain): string
    {
        return Yii::$app->security->encryptByKey($plain, self::key());
    }

    public static function decrypt(?string $cipher): ?string
    {
        if ($cipher === null || $cipher === '') {
            return null;
        }
        $plain = Yii::$app->security->decryptByKey($cipher, self::key());
        return is_string($plain) && $plain !== '' ? $plain : null;
    }

    /** The shared executor key; the same file is read by web and console applications. */
    public static function key(): string
    {
        $file = Yii::getAlias('@root/frontend/runtime/skeeks-ai-bridge.key');
        $key = is_file($file) ? trim((string)file_get_contents($file)) : '';
        if ($key === '') {
            throw new \RuntimeException('Подключение SkeekS AI не настроено.');
        }
        return $key;
    }

    /** One installation client instead of a dynamic registration on every service start. */
    private static function client(): CmsOauthClient
    {
        $client = CmsOauthClient::find()->andWhere(['client_id' => self::CLIENT_ID])->one();
        if ($client) {
            return $client;
        }
        $client = new CmsOauthClient([
            'client_id' => self::CLIENT_ID,
            'secret_hash' => password_hash(Yii::$app->security->generateRandomString(48), PASSWORD_DEFAULT),
            'name' => 'SkeekS AI',
            'redirect_uris' => Json::encode([]),
            'scopes' => Json::encode([]),
            'scope_mode' => CmsOauthClient::SCOPE_MODE_DYNAMIC,
            'is_active' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        if (!$client->save()) {
            throw new \RuntimeException('Не удалось зарегистрировать клиент SkeekS AI.');
        }
        return $client;
    }
}
