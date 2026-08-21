<?php

namespace App\News\Form;

use App\News\Entity\UserNews;
use App\Shared\ValueObject\Language;
use App\Taxonomy\Entity\Taxonomy;
use App\Taxonomy\Enum\TaxonomyStatus;
use App\Taxonomy\Repository\TaxonomyRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Formulaire de création/édition d'une "Une" (§3.4, prompt de conception parcours 1.G). Ne
 * propose que des taxonomies `VALIDATED` de la langue courante, comme l'exige §10.4 (les champs
 * association du back-office EasyAdmin appliquent déjà cette même restriction, voir
 * `TaxonomyCrudController`) — restreindre le choix du formulaire est ici la seule garde
 * disponible : `UserNews::addTaxonomy()` refuse une taxonomie non validée, mais Symfony Form
 * édite la collection directement (`by_reference: true`), sans repasser par cette méthode.
 */
final class UserNewsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $language = $options['language'];

        $builder
            ->add('label', TextType::class, [
                'label' => 'user_news.form.label',
                'constraints' => [new NotBlank(), new Length(max: 512)],
            ])
            ->add('taxonomies', EntityType::class, [
                'class' => Taxonomy::class,
                'label' => 'user_news.form.taxonomies',
                'multiple' => true,
                'choice_label' => 'label',
                'by_reference' => false,
                'query_builder' => static fn (TaxonomyRepository $repository): QueryBuilder => $repository
                    ->createQueryBuilder('t')
                    ->andWhere('t.status = :status')
                    ->andWhere('t.language = :language')
                    ->setParameter('status', TaxonomyStatus::VALIDATED)
                    ->setParameter('language', $language)
                    ->orderBy('t.label', 'ASC'),
            ])
            ->add('private', CheckboxType::class, [
                'label' => 'user_news.form.private',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => UserNews::class])
            ->setRequired('language')
            ->setAllowedTypes('language', Language::class);
    }
}
