<?php

declare(strict_types=1);

namespace skeeks\cms\ai\service;

use Yii;

/**
 * Models of SkeekS AI and their official API prices (USD per million tokens) as a cost reference.
 * params cmsAi.models = [
 *     '<model id>' => ['title' => '…', 'engine' => 'codex', 'input' => 0.0, 'cached' => 0.0, 'output' => 0.0],
 * ]
 * params cmsAi.engineModels = ['codex' => '<model id used when the engine does not report one>'].
 * Prices are filled by the provider from the vendors' official price lists; an unknown price gives no estimate.
 */
final class ModelCatalog
{
    public static function models(): array
    {
        return Yii::$app->params['cmsAi']['models'] ?? [];
    }

    /** The model of a run: reported by the engine, else the configured default of the engine. */
    public static function resolve(string $engine, string $reported): string
    {
        if ($reported !== '') {
            return mb_substr($reported, 0, 100);
        }
        $engineKey = preg_replace('/-.*$/', '', $engine);
        return (string)(Yii::$app->params['cmsAi']['engineModels'][$engine] ?? Yii::$app->params['cmsAi']['engineModels'][$engineKey] ?? '');
    }

    public static function title(string $model): string
    {
        return (string)(self::models()[$model]['title'] ?? $model);
    }

    /**
     * Reference cost in USD: the engine's own figure when it reported one for every call,
     * else the catalog price; [null, null] when neither is known.
     *
     * @return array [float|null $usd, string|null $source]
     */
    public static function estimate(string $model, int $input, int $cached, int $output, ?float $engineCost): array
    {
        if ($engineCost !== null) {
            return [round($engineCost, 6), 'engine'];
        }
        $price = self::models()[$model] ?? null;
        if (!$price || !isset($price['input'], $price['output'])) {
            return [null, null];
        }
        $cachedPrice = $price['cached'] ?? $price['input'];
        $usd = (max(0, $input - $cached) * $price['input'] + $cached * $cachedPrice + $output * $price['output']) / 1000000;
        return [round($usd, 6), 'catalog'];
    }
}
