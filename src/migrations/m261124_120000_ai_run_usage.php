<?php

use yii\db\Migration;

/**
 * Token usage of a run, summed over its model calls. Cost is a reference in USD:
 * reported by the engine or estimated from the model catalog prices; null when unknown.
 */
class m261124_120000_ai_run_usage extends Migration
{
    public function safeUp()
    {
        $table = '{{%cms_ai_run}}';
        $this->addColumn($table, 'model', $this->string(100));
        $this->addColumn($table, 'tokens_input', $this->bigInteger()->notNull()->defaultValue(0));
        $this->addColumn($table, 'tokens_cached', $this->bigInteger()->notNull()->defaultValue(0));
        $this->addColumn($table, 'tokens_output', $this->bigInteger()->notNull()->defaultValue(0));
        $this->addColumn($table, 'model_calls', $this->integer()->notNull()->defaultValue(0));
        $this->addColumn($table, 'tool_calls', $this->integer()->notNull()->defaultValue(0));
        $this->addColumn($table, 'cost_usd', $this->decimal(14, 6));
        $this->addColumn($table, 'cost_source', $this->string(16));
        $this->createIndex('ai_run_created', $table, ['created_by', 'created_at']);
    }

    public function safeDown()
    {
        $table = '{{%cms_ai_run}}';
        $this->dropIndex('ai_run_created', $table);
        foreach (['model', 'tokens_input', 'tokens_cached', 'tokens_output', 'model_calls', 'tool_calls', 'cost_usd', 'cost_source'] as $column) {
            $this->dropColumn($table, $column);
        }
    }
}
