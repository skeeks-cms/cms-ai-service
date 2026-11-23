<?php

declare(strict_types=1);

if (getenv('SKEEKS_AI_TEST') !== 'local') {
    throw new RuntimeException('Set SKEEKS_AI_TEST=local.');
}
define('ROOT_DIR', '/app');
require ROOT_DIR . '/vendor/skeeks/cms/bootstrap.php';
if (!defined('SKEEKS_LOCAL_STAND') || SKEEKS_LOCAL_STAND !== true) {
    throw new RuntimeException('Local stand required.');
}

$config = new Yiisoft\Config\Config(new Yiisoft\Config\ConfigPaths(ROOT_DIR, 'config'), null, [
    Yiisoft\Config\Modifier\RecursiveMerge::groups('web', 'web-' . ENV, 'params', 'params-web-' . ENV),
], 'params-web-' . ENV);
$settings = $config->get($config->has('web-' . ENV) ? 'web-' . ENV : 'web');
$settings['components']['request']['scriptFile'] = '/app/frontend/web/index.php';
$settings['components']['request']['scriptUrl'] = '/index.php';
$settings['components']['request']['hostInfo'] = 'http://localhost:8080';
$settings['components']['user']['enableSession'] = false;
new yii\web\Application($settings);
Yii::$app->errorHandler->unregister();
Yii::setAlias('@skeeks/cms/ai/service', dirname(__DIR__) . '/src');
Yii::$app->params['cmsMcp']['toolProviders'][] = skeeks\cms\ai\service\TaskToolProvider::class;
Yii::$app->user->setIdentity(skeeks\cms\models\CmsUser::findOne(1));

$db = Yii::$app->db;
if (!$db->schema->getTableSchema('{{%cms_ai_task_request}}')) {
    throw new RuntimeException('Apply the pilot migration on the local stand first.');
}
$transaction = $db->beginTransaction();
$exit = 0;
try {
    $suffix = bin2hex(random_bytes(5));
    $company = new skeeks\cms\models\CmsCompany(['name' => 'AI pilot fixture ' . $suffix]);
    if (!$company->save(false)) {
        throw new RuntimeException('Fixture creation failed.');
    }
    $token = new skeeks\cms\oauth2\models\CmsOauthAccessToken(['scopes' => json_encode([
        'cms.task.write', 'cms.task.read', 'cms.company.read', 'cms.deal.read',
    ])]);
    $input = [
        'request_key' => bin2hex(random_bytes(16)),
        'title' => 'AI task fixture ' . $suffix,
        'description' => 'Fixture',
        'company_id' => (int)$company->id,
        'service_id' => 0,
    ];
    $call = fn(array $payload) => Yii::$app->cmsMcp->executeTool('cms_ai_task_create', $payload, $token, ['transport' => 'test']);
    $first = $call($input);
    $replay = $call($input);
    $input['request_key'] = bin2hex(random_bytes(16));
    $duplicate = $call($input);
    if ($first !== $replay || !$first['id'] || !$first['url'] || $first['existing'] ||
        !$duplicate['existing'] || $duplicate['id'] !== $first['id']) {
        throw new RuntimeException('Task result or idempotency not verified.');
    }

    $input['description'] = 'Changed after confirmation';
    try {
        $call($input);
        throw new RuntimeException('Key reused for different data.');
    } catch (InvalidArgumentException $expected) {}

    $token->scopes = json_encode(['cms.task.read']);
    try {
        $call($input);
        throw new RuntimeException('Task write without the OAuth scope.');
    } catch (yii\web\ForbiddenHttpException $expected) {}
    echo "OK: creation, readback, replay, duplicate, fingerprint and OAuth write scope.\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . "\n");
    $exit = 1;
} finally {
    $transaction->rollBack();
}
exit($exit);
