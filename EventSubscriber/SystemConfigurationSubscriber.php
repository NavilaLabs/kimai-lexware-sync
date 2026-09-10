<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\EventSubscriber;

use App\Event\SystemConfigurationEvent;
use App\Form\Model\Configuration;
use App\Form\Model\SystemConfiguration as SystemConfigurationModel;
use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Form\LexwareApiKeyType;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SystemConfigurationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [SystemConfigurationEvent::class => ['onSystemConfiguration', 200]];
    }

    public function onSystemConfiguration(SystemConfigurationEvent $event): void
    {
        $event->addConfiguration(
            (new SystemConfigurationModel('lexware_sync_configuration'))
                ->setConfiguration([
                    (new Configuration('lexware_sync.license_key'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(LexwareApiKeyType::class)
                        ->setRequired(false)
                        ->setOptions(['help' => 'lexware_sync.license_key_help']),
                    (new Configuration('lexware_sync.api_key'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(LexwareApiKeyType::class)
                        ->setRequired(false)
                        ->setOptions(['help' => $this->buildConnectWebhooksHelpHtml(), 'help_html' => true]),
                    (new Configuration('lexware_sync.public_base_url'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(TextType::class)
                        ->setRequired(false)
                        ->setConstraints([new Url()]),
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
                    (new Configuration('lexware_sync.derive_budget_enabled'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(CheckboxType::class)
                        ->setRequired(false),
                    (new Configuration('lexware_sync.budget_unit_regex'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(TextType::class)
                        ->setRequired(false)
                        ->setValue(LexwareSyncConfiguration::DEFAULT_BUDGET_UNIT_REGEX)
                        ->setConstraints([$this->createRegexConstraint()]),
                    (new Configuration('lexware_sync.reconcile_interval_minutes'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(IntegerType::class)
                        ->setRequired(false)
                        ->setValue(LexwareSyncConfiguration::DEFAULT_RECONCILE_INTERVAL_MINUTES),
                    (new Configuration('lexware_sync.check_api_key_interval_days'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(IntegerType::class)
                        ->setRequired(false)
                        ->setValue(LexwareSyncConfiguration::DEFAULT_CHECK_API_KEY_INTERVAL_DAYS),
                    (new Configuration('lexware_sync.check_license_interval_days'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(IntegerType::class)
                        ->setRequired(false)
                        ->setValue(LexwareSyncConfiguration::DEFAULT_CHECK_LICENSE_INTERVAL_DAYS),
                    (new Configuration('lexware_sync.invoice_title_regex'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(TextType::class)
                        ->setRequired(false)
                        ->setConstraints([$this->createRegexConstraint()]),
                    (new Configuration('lexware_sync.project_title_source'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(ChoiceType::class)
                        ->setRequired(false)
                        ->setValue(LexwareSyncConfiguration::DEFAULT_PROJECT_TITLE_SOURCE)
                        ->setOptions([
                            'choices' => [
                                'Voucher number' => LexwareSyncConfiguration::PROJECT_TITLE_VOUCHER_NUMBER,
                                'Order confirmation title' => LexwareSyncConfiguration::PROJECT_TITLE_ORDER_CONFIRMATION_TITLE,
                                'Customer and title' => LexwareSyncConfiguration::PROJECT_TITLE_CUSTOMER_AND_TITLE,
                            ],
                        ]),
                    (new Configuration('lexware_sync.project_completion_mode'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(ChoiceType::class)
                        ->setRequired(false)
                        ->setValue(LexwareSyncConfiguration::DEFAULT_PROJECT_COMPLETION_MODE)
                        ->setOptions([
                            'choices' => [
                                'End date' => LexwareSyncConfiguration::PROJECT_COMPLETION_END_DATE,
                                'Hidden' => LexwareSyncConfiguration::PROJECT_COMPLETION_HIDDEN,
                            ],
                        ]),
                ])
        );
    }

    private function buildConnectWebhooksHelpHtml(): string
    {
        $url = $this->urlGenerator->generate('lexware_sync_connect_webhooks');
        $token = $this->csrfTokenManager->getToken('lexware_sync_connect_webhooks')->getValue();
        $label = $this->translator->trans('lexware_sync.connect_webhooks', [], 'messages');
        $runningLabel = $this->translator->trans('lexware_sync.connect_webhooks_running', [], 'messages');

        return <<<HTML
            <div class="mt-2">
                <button type="button" class="btn btn-secondary btn-sm"
                        data-lexware-connect-webhooks
                        data-url="{$this->escape($url)}"
                        data-token="{$this->escape($token)}"
                        data-label="{$this->escape($label)}"
                        data-running-label="{$this->escape($runningLabel)}">{$this->escape($label)}</button>
                <span data-lexware-connect-webhooks-result class="ms-2"></span>
            </div>
            <script>
                document.addEventListener('click', function (event) {
                    var button = event.target.closest('[data-lexware-connect-webhooks]');
                    if (!button) {
                        return;
                    }

                    var result = button.parentElement.querySelector('[data-lexware-connect-webhooks-result]');
                    button.disabled = true;
                    button.textContent = button.dataset.runningLabel;
                    result.textContent = '';

                    fetch(button.dataset.url, {
                        method: 'POST',
                        headers: {'X-CSRF-TOKEN': button.dataset.token},
                    })
                        .then(function (response) { return response.json(); })
                        .then(function (body) {
                            result.textContent = body.message;
                            result.className = body.success ? 'ms-2 text-success' : 'ms-2 text-danger';
                        })
                        .catch(function () {
                            result.textContent = 'Request failed.';
                            result.className = 'ms-2 text-danger';
                        })
                        .finally(function () {
                            button.disabled = false;
                            button.textContent = button.dataset.label;
                        });
                });
            </script>
            HTML;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES, 'UTF-8');
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
