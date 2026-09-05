<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\EventSubscriber;

use App\Event\SystemConfigurationEvent;
use App\Form\Model\Configuration;
use App\Form\Model\SystemConfiguration as SystemConfigurationModel;
use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class SystemConfigurationSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [SystemConfigurationEvent::class => ['onSystemConfiguration', 200]];
    }

    public function onSystemConfiguration(SystemConfigurationEvent $event): void
    {
        $event->addConfiguration(
            (new SystemConfigurationModel('lexware_sync_configuration'))
                ->setConfiguration([
                    (new Configuration('lexware_sync.auto_convert_enabled'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(CheckboxType::class)
                        ->setRequired(false),
                    (new Configuration('lexware_sync.title_regex'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(TextType::class)
                        ->setRequired(false)
                        ->setConstraints([$this->createRegexConstraint()]),
                    (new Configuration('lexware_sync.read_lines_enabled'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(CheckboxType::class)
                        ->setRequired(false),
                    (new Configuration('lexware_sync.line_regex'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(TextType::class)
                        ->setRequired(false)
                        ->setConstraints([$this->createRegexConstraint()]),
                    (new Configuration('lexware_sync.reconcile_interval_minutes'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(IntegerType::class)
                        ->setRequired(false),
                    (new Configuration('lexware_sync.invoice_title_regex'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(TextType::class)
                        ->setRequired(false)
                        ->setConstraints([$this->createRegexConstraint()]),
                    (new Configuration('lexware_sync.project_completion_mode'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(ChoiceType::class)
                        ->setRequired(false)
                        ->setOptions([
                            'choices' => [
                                'End date' => LexwareSyncConfiguration::PROJECT_COMPLETION_END_DATE,
                                'Hidden' => LexwareSyncConfiguration::PROJECT_COMPLETION_HIDDEN,
                            ],
                        ]),
                ])
        );
    }

    private function createRegexConstraint(): Callback
    {
        return new Callback(function (mixed $value, ExecutionContextInterface $context): void {
            if ($value === null || $value === '') {
                return;
            }

            if (!\is_string($value) || @preg_match($value, '') === false) {
                $context->addViolation('This is not a valid regular expression.');
            }
        });
    }
}
