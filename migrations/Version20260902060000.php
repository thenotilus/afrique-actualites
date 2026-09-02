<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Synthèses hebdomadaires par pays/région (§ "Nouvelle table syntheses") : la table `sf_synthesis`
 * (une synthèse par pays OU par région de repli, jamais les deux — voir
 * `App\Synthesis\Entity\Synthesis::forCountry()`/`forRegion()`), sa table de liaison
 * `sf_synthesis_article` (traçabilité des articles sources), et la colonne `region` ajoutée à
 * `sf_country` (repli régional, voir `App\Geography\Enum\Region`).
 *
 * Écrite à la main plutôt que générée par `doctrine:migrations:diff` : comme documenté dans
 * `docs/architecture.md` ("Migration Doctrine — action requise avant la mise en production"),
 * aucune base MySQL n'est joignable dans cet environnement de développement pour produire un diff
 * dans le bon dialecte — la même contrainte qui a empêché de générer la toute première migration
 * du dépôt (les 9 entités historiques n'en ont encore aucune). Le mapping des nouvelles entités a
 * en revanche été validé avec `doctrine:schema:validate` contre une base SQLite locale (comme le
 * reste du dépôt), qui confirme les noms de colonnes, index et contraintes ci-dessous. À
 * confirmer/régénérer avec `doctrine:migrations:diff` dès qu'une base MySQL 8 est joignable.
 */
final class Version20260902060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les synthèses hebdomadaires par pays/région (sf_synthesis, sf_synthesis_article) et la région de repli des pays (sf_country.region)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE sf_synthesis (
                id INT AUTO_INCREMENT NOT NULL,
                country_id INT DEFAULT NULL,
                reviewed_by_id INT DEFAULT NULL,
                region VARCHAR(255) DEFAULT NULL,
                language VARCHAR(255) NOT NULL,
                week_start DATETIME NOT NULL,
                week_end DATETIME NOT NULL,
                title VARCHAR(255) NOT NULL,
                lead LONGTEXT NOT NULL,
                body LONGTEXT NOT NULL,
                status VARCHAR(255) NOT NULL,
                generated_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                published_at DATETIME DEFAULT NULL,
                INDEX IDX_674A04CDF92F3E70 (country_id),
                INDEX IDX_674A04CDFC6B21F1 (reviewed_by_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE sf_synthesis_article (
                synthesis_id INT NOT NULL,
                article_id INT NOT NULL,
                INDEX IDX_E64F1BB9EC91FE48 (synthesis_id),
                INDEX IDX_E64F1BB97294869C (article_id),
                PRIMARY KEY(synthesis_id, article_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE sf_synthesis ADD CONSTRAINT FK_674A04CDF92F3E70 FOREIGN KEY (country_id) REFERENCES sf_country (id)');
        $this->addSql('ALTER TABLE sf_synthesis ADD CONSTRAINT FK_674A04CDFC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES sf_user (id)');
        $this->addSql('ALTER TABLE sf_synthesis_article ADD CONSTRAINT FK_E64F1BB9EC91FE48 FOREIGN KEY (synthesis_id) REFERENCES sf_synthesis (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sf_synthesis_article ADD CONSTRAINT FK_E64F1BB97294869C FOREIGN KEY (article_id) REFERENCES sf_article (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE sf_country ADD region VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sf_synthesis_article DROP FOREIGN KEY FK_E64F1BB9EC91FE48');
        $this->addSql('ALTER TABLE sf_synthesis_article DROP FOREIGN KEY FK_E64F1BB97294869C');
        $this->addSql('ALTER TABLE sf_synthesis DROP FOREIGN KEY FK_674A04CDF92F3E70');
        $this->addSql('ALTER TABLE sf_synthesis DROP FOREIGN KEY FK_674A04CDFC6B21F1');

        $this->addSql('DROP TABLE sf_synthesis_article');
        $this->addSql('DROP TABLE sf_synthesis');

        $this->addSql('ALTER TABLE sf_country DROP region');
    }
}
