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

    /** Models of an engine available to this installation: [['id' => …, 'title' => …]]. */
    public static function forEngine(string $engine): array
    {
        $engineKey = preg_replace('/-.*$/', '', $engine);
        $result = [];
        foreach (self::models() as $id => $model) {
            if (($model['engine'] ?? '') === $engineKey && empty($model['hidden'])) {
                $result[] = ['id' => (string)$id, 'title' => (string)($model['title'] ?? $id),
                    'efforts' => array_values(array_intersect(self::EFFORTS, (array)($model['efforts'] ?? [])))];
            }
        }
        return $result;
    }

    /** Reasoning levels the executor may pass; the model list narrows them. */
    public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    /** Efforts of a model of the engine, or of the engine default (params cmsAi.engineEfforts). */
    public static function efforts(string $engine, string $model): array
    {
        if ($model !== '') {
            foreach (self::forEngine($engine) as $item) {
                if ($item['id'] === $model) {
                    return $item['efforts'];
                }
            }
            return [];
        }
        $engineKey = preg_replace('/-.*$/', '', $engine);
        return array_values(array_intersect(self::EFFORTS, (array)(Yii::$app->params['cmsAi']['engineEfforts'][$engineKey] ?? [])));
    }

    public static function allowed(string $engine, string $model): bool
    {
        return in_array($model, array_column(self::forEngine($engine), 'id'), true);
    }

    /** Context window of a model in tokens (params: model 'context', else cmsAi.engineContext[engine]); 0 if unknown. */
    public static function contextWindow(string $engine, string $model): int
    {
        if ($model !== '' && isset(self::models()[$model]['context'])) {
            return (int)self::models()[$model]['context'];
        }
        $engineKey = preg_replace('/-.*$/', '', $engine);
        return (int)(Yii::$app->params['cmsAi']['engineContext'][$engineKey] ?? 0);
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
