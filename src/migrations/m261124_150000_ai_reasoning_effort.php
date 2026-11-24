<?php

use yii\db\Migration;

/** Reasoning effort chosen in a chat and used by a run; null means the engine default. */
class m261124_150000_ai_reasoning_effort extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%cms_ai_chat}}', 'effort', $this->string(16));
        $this->addColumn('{{%cms_ai_run}}', 'effort', $this->string(16));
    }

    public function safeDown()
    {
        $this->dropColumn('{{%cms_ai_run}}', 'effort');
        $this->dropColumn('{{%cms_ai_chat}}', 'effort');
    }
}
