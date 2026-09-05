<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Twig;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareDeepLink;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class LexwareDeepLinkExtension extends AbstractExtension
{
    public function __construct(private readonly LexwareDeepLink $deepLink)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('lexware_order_confirmation_url', [$this->deepLink, 'forOrderConfirmation']),
            new TwigFunction('lexware_invoice_url', [$this->deepLink, 'forInvoice']),
        ];
    }
}
