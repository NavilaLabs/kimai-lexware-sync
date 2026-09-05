<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Repository\Query;

use KimaiPlugin\KimaiLexwareSyncBundle\Enum\DocumentStatusFilter;
use Symfony\Component\HttpFoundation\ParameterBag;

final readonly class DocumentListQuery
{
    public const PAGE_SIZE = 25;

    public function __construct(
        public DocumentStatusFilter $status,
        public ?string $searchTerm,
        public int $page,
    ) {
    }

    public static function fromParameters(ParameterBag $parameters): self
    {
        return new self(
            DocumentStatusFilter::fromRequestValue($parameters->get('status')),
            self::readSearchTerm($parameters),
            max(1, $parameters->getInt('page', 1)),
        );
    }

    public function withStatus(DocumentStatusFilter $status): self
    {
        return new self($status, $this->searchTerm, 1);
    }

    public function getSearchPattern(): ?string
    {
        if ($this->searchTerm === null) {
            return null;
        }

        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $this->searchTerm) . '%';
    }

    /**
     * @return array<string, string>
     */
    public function toRouteParameters(): array
    {
        $parameters = ['status' => $this->status->value];

        if ($this->searchTerm !== null) {
            $parameters['search'] = $this->searchTerm;
        }

        return $parameters;
    }

    private static function readSearchTerm(ParameterBag $parameters): ?string
    {
        $searchTerm = $parameters->get('search');
        if (!\is_string($searchTerm)) {
            return null;
        }

        $searchTerm = trim($searchTerm);

        return $searchTerm === '' ? null : $searchTerm;
    }
}
