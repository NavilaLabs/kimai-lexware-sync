<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

final class LexwareApiKeyType extends AbstractType
{
    public function getParent(): string
    {
        return PasswordType::class;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $storedApiKey = null;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use (&$storedApiKey): void {
            $storedApiKey = $event->getData();
        });

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event) use (&$storedApiKey): void {
            if ($event->getData() === null || $event->getData() === '') {
                $event->setData($storedApiKey);
            }
        });
    }
}
