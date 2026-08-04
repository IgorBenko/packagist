<?php declare(strict_types=1);

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Form\Type;

use App\Form\Model\OrganizationPolicyRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<OrganizationPolicyRequest>
 */
class OrganizationPolicyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enforceTwoFactor', CheckboxType::class, [
                'label' => 'Require two-factor authentication',
                'required' => false,
                'help' => 'Owners always need two-factor authentication, however this is set. Enabling it extends the same requirement to every other member: those without it are suspended as soon as you save, keeping their membership and teams but unable to act for the organization until they enable it.',
            ])
            ->add('allowedEmailDomains', TextType::class, [
                'label' => 'Required email address domains',
                'required' => false,
                'empty_data' => '',
                'attr' => ['placeholder' => 'acme.com, acme.org'],
                'help' => 'Separate several domains with commas; a member on any one of them satisfies the requirement. Members whose account email is on another domain are suspended as soon as you save. Leave empty to accept any address. Your own account email must be on one of the domains you require.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => OrganizationPolicyRequest::class,
        ]);
    }
}
