<?php

use yii\db\Migration;

/** Context of the run's last model call: its size and the service's own parts (characters). */
class m261124_170000_ai_run_context extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%cms_ai_run}}', 'context', $this->text());
    }

    public function safeDown()
    {
        $this->dropColumn('{{%cms_ai_run}}', 'context');
    }
}
