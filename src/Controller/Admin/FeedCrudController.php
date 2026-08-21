<?php

namespace App\Controller\Admin;

use App\Feed\Entity\Feed;
use App\Shared\ValueObject\Language;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;

/**
 * Gestion des flux RSS/Atom (§3.1 de la documentation fonctionnelle). Chaque flux porte
 * désormais une langue explicite (§3bis), nécessaire au pipeline de classification bilingue.
 *
 * @extends AbstractCrudController<Feed>
 */
class FeedCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Feed::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Flux')
            ->setEntityLabelInPlural('Flux RSS')
            ->setDefaultSort(['active' => 'DESC', 'label' => 'ASC']);
    }

    /**
     * Feed n'a pas de constructeur sans argument (url et langue sont obligatoires dès la
     * création) : le formulaire "Nouveau" écrasera ces valeurs par défaut avec la saisie réelle.
     */
    public function createEntity(string $entityFqcn): Feed
    {
        return new Feed('', Language::FRENCH);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('label', 'Nom du flux');
        yield UrlField::new('url', 'URL');
        yield ChoiceField::new('language', 'Langue')
            ->setChoices(array_combine(
                array_map(static fn (Language $language) => $language->label(), Language::cases()),
                Language::cases(),
            ));
        yield BooleanField::new('active', 'Actif')->renderAsSwitch();
        yield BooleanField::new('sponsored', 'Sponsorisé')->renderAsSwitch();
        yield TextareaField::new('description', 'Description')->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Ajouté le')->hideOnForm();
    }
}
