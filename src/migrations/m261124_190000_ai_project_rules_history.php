<?php

use yii\db\Migration;

/** Editions of the project rules and skills: who changed them and when; the current edition stays in cms_ai_project. */
class m261124_190000_ai_project_rules_history extends Migration
{
    public function safeUp()
    {
        $options = $this->db->driverName === 'mysql' ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB' : null;
        $this->addColumn('{{%cms_ai_project}}', 'updated_by', $this->integer());
        $this->addColumn('{{%cms_ai_project}}', 'updated_at', $this->integer());
        $this->createTable('{{%cms_ai_project_rules_edition}}', [
            'id' => $this->primaryKey(),
            'site_id' => $this->integer()->notNull(),
            'revision' => $this->integer()->notNull(),
            'instructions' => $this->text()->notNull(),
            'skills' => $this->text()->notNull(),
            'user_id' => $this->integer(),
            'created_at' => $this->integer()->notNull(),
        ], $options);
        $this->createIndex('ai_project_rules_edition', '{{%cms_ai_project_rules_edition}}', ['site_id', 'revision'], true);
        // The current editions become the first entries of the history.
        $this->execute('INSERT INTO {{%cms_ai_project_rules_edition}} (site_id, revision, instructions, skills, created_at)
            SELECT site_id, revision, instructions, skills, :now FROM {{%cms_ai_project}}', [':now' => time()]);
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_ai_project_rules_edition}}');
        $this->dropColumn('{{%cms_ai_project}}', 'updated_at');
        $this->dropColumn('{{%cms_ai_project}}', 'updated_by');
    }
}
