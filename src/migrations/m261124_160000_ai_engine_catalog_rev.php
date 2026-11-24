<?php

use yii\db\Migration;

/** Revision of the operations catalog already given to an engine session; it is not resent. */
class m261124_160000_ai_engine_catalog_rev extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%cms_ai_engine_session}}', 'catalog_rev', 'CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL');
    }

    public function safeDown()
    {
        $this->dropColumn('{{%cms_ai_engine_session}}', 'catalog_rev');
    }
}
