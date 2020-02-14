<?php

declare(strict_types=1);

/*
 * @copyright   2019 Mautic, Inc. All rights reserved
 * @author      Mautic, Inc.
 *
 * @link        https://mautic.com
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\CustomObjectsBundle\Form\Type;

use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Model\CustomFieldModel;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemRouteProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Translation\TranslatorInterface;

class CampaignConditionFieldValueType extends AbstractType
{
    /**
     * @var CustomFieldModel
     */
    protected $customFieldModel;

    /**
     * @var CustomItemRouteProvider
     */
    protected $routeProvider;

    /**
     * @var TranslatorInterface
     */
    protected $translator;

    public function __construct(
        CustomFieldModel $customFieldModel,
        CustomItemRouteProvider $routeProvider,
        TranslatorInterface $translator
    ) {
        $this->customFieldModel = $customFieldModel;
        $this->routeProvider    = $routeProvider;
        $this->translator       = $translator;
    }

    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $fields = $this->customFieldModel->fetchCustomFieldsForObject($options['customObject']);

        $builder->add(
            'field',
            ChoiceType::class,
            [
                'required' => true,
                'label'    => 'custom.item.field',
                'choices'  => $fields,
                'attr'     => [
                    'class' => 'form-control',
                ],
                'choice_attr' => function ($fieldId) use ($fields) {
                    /** @var CustomField $field */
                    $field = $fields[$fieldId];

                    return [
                        'data-operators'  => json_encode($field->getTypeObject()->getOperatorOptions()),
                        'data-options'    => json_encode($field->getChoices()),
                        'data-field-type' => $field->getType(),
                    ];
                },
            ]
        );

        if (isset($fields[$options['data']['field']])) {
            $selectedField = $fields[$options['data']['field']];
        } else {
            $selectedField = array_values($fields)[0];
        }

        $operators = $selectedField->getTypeObject()->getOperatorOptions();

        $builder->add(
            'operator',
            ChoiceType::class,
            [
                'required' => true,
                'label'    => 'custom.item.operator',
                'choices'  => $operators,
                'attr'     => ['class' => 'link-custom-item-id'],
            ]
        );

        // Disable operator choice validation as each field has different operators.
        $builder->get('operator')->resetViewTransformers();

        $builder->add(
            'value',
            TextType::class,
            [
                'required' => true,
                'label'    => 'custom.item.field.value',
                'attr'     => ['class' => 'form-control'],
            ]
        );

        $builder->add(
            'customObjectId',
            HiddenType::class,
            ['data' => $options['customObject']->getId()]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['customObject']);
    }
}
