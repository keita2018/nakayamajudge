<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260130191000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add contestteamleft table to block re-entry after logout.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "CREATE TABLE `contestteamleft` (
                `cid` int(4) unsigned NOT NULL COMMENT 'Contest ID',
                `teamid` int(4) unsigned NOT NULL COMMENT 'Team ID',
                `lefttime` decimal(32,9) unsigned NOT NULL COMMENT 'Time team left contest',
                PRIMARY KEY (`cid`,`teamid`),
                KEY `cid` (`cid`),
                KEY `teamid` (`teamid`),
                CONSTRAINT `contestteamleft_ibfk_1` FOREIGN KEY (`cid`) REFERENCES `contest` (`cid`) ON DELETE CASCADE,
                CONSTRAINT `contestteamleft_ibfk_2` FOREIGN KEY (`teamid`) REFERENCES `team` (`teamid`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Teams that left a contest'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE contestteamleft');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
