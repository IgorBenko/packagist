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
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => OrganizationPolicyRequest::class,
        ]);
    }
}
