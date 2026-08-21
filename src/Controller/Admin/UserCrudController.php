<?php

namespace App\Controller\Admin;

use App\User\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;

/**
 * Comptes utilisateurs du site (§2, §9.1) : ceux qui créent des "Unes" personnalisées ou
 * accèdent au back-office. Ne pas confondre avec les abonnés de la newsletter
 * (NewsletterSubscriberCrudController), un modèle volontairement distinct (§3.7).
 *
 * Le mot de passe n'est volontairement pas éditable ici : la création de compte avec mot de
 * passe (inscription, réinitialisation) est un parcours public à part entière, prévu en phase 6.
 *
 * @extends AbstractCrudController<User>
 */
class UserCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Utilisateur')
            ->setEntityLabelInPlural('Comptes utilisateurs')
            ->setDefaultSort(['email' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield EmailField::new('email', 'Email');
        yield ChoiceField::new('roles', 'Rôles')
            ->setChoices(['Administrateur' => User::ROLE_ADMIN])
            ->allowMultipleChoices()
            ->renderExpanded()
            ->renderAsBadges();
        yield DateTimeField::new('createdAt', 'Inscrit le')->hideOnForm();
    }

    public function createEntity(string $entityFqcn): User
    {
        return new User('');
    }
}
