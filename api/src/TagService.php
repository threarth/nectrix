<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Chaorganix;

/**
 * Commands on Tag and on their assignment to Document. A Tag is a dimension of its own: it never
 * becomes a Concept, an Entity or an EntityType, and the same name in two dimensions stays two
 * different things.
 */
final class TagService
{
    private const MAX_NAME_LENGTH = 100;

    public function __construct(
        private readonly TagRepository $repository,
        private readonly DocumentRepository $documents,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->repository->list();
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function create(array $input): array
    {
        $this->assertOnlyKeys($input, ['name']);
        $name = $this->name($input['name'] ?? null);
        $existing = $this->repository->findByName($name);
        if ($existing !== null) {
            throw new ApiException(422, 'tag_duplicate', 'Esiste già un Tag con questo nome.', ['tag' => $existing]);
        }
        return $this->repository->create($name);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function rename(string $tagId, array $input): array
    {
        $this->assertOnlyKeys($input, ['name']);
        $this->require($tagId);
        $name = $this->name($input['name'] ?? null);
        $existing = $this->repository->findByName($name);
        if ($existing !== null && $existing['id'] !== $tagId) {
            throw new ApiException(422, 'tag_duplicate', 'Esiste già un Tag con questo nome.', ['tag' => $existing]);
        }
        $this->repository->rename($tagId, $name);
        return $this->require($tagId);
    }

    /** Deletion is prudent: an assigned Tag must be removed from its Document first. */
    public function delete(string $tagId): void
    {
        $this->require($tagId);
        $assignments = $this->repository->assignmentCount($tagId);
        if ($assignments > 0) {
            throw new ApiException(409, 'tag_assigned', "Il Tag è assegnato a {$assignments} Document: rimuovilo prima di eliminarlo.", [
                'documents' => $assignments,
            ]);
        }
        $this->repository->delete($tagId);
    }

    /** @return list<array<string, mixed>> */
    public function assign(string $documentId, array $input): array
    {
        $this->assertOnlyKeys($input, ['tagId']);
        $this->assertWritableDocument($documentId);
        $tagId = $this->requiredId($input['tagId'] ?? null);
        $this->require($tagId);
        $this->repository->assign($documentId, $tagId);
        return $this->repository->documentTags($documentId);
    }

    /** @return list<array<string, mixed>> */
    public function unassign(string $documentId, string $tagId): array
    {
        $this->assertWritableDocument($documentId);
        $this->require($tagId);
        $this->repository->unassign($documentId, $tagId);
        return $this->repository->documentTags($documentId);
    }

    /** @return list<array<string, mixed>> */
    public function documentTags(string $documentId): array
    {
        $this->documents->get($documentId);
        return $this->repository->documentTags($documentId);
    }

    /**
     * Document carrying every requested Tag, or null when no Tag filter was asked for.
     *
     * @return list<string>|null
     */
    public function documentIds(string $tagIds): ?array
    {
        $requested = array_values(array_filter(array_map('trim', explode(',', $tagIds)), static fn (string $id): bool => $id !== ''));
        if ($requested === []) {
            return null;
        }
        foreach ($requested as $tagId) {
            $this->require($tagId);
        }
        return $this->repository->documentIdsWithAllTags(array_values(array_unique($requested)));
    }

    /** @return array<string, mixed> */
    private function require(string $tagId): array
    {
        $this->assertId($tagId);
        $tag = $this->repository->find($tagId);
        if ($tag === null) {
            throw new ApiException(404, 'tag_not_found', 'Tag non trovato.');
        }
        return $tag;
    }

    private function assertWritableDocument(string $documentId): void
    {
        $document = $this->documents->get($documentId);
        if ($document['status'] !== 'active') {
            throw new ApiException(409, 'document_read_only', 'Un Document archiviato o nel cestino è in sola lettura.');
        }
    }

    private function name(mixed $value): string
    {
        $name = is_string($value) ? ltrim(trim($value), '#') : '';
        $name = trim($name);
        if ($name === '' || Text::length($name) > self::MAX_NAME_LENGTH) {
            throw new ApiException(422, 'invalid_request', 'Il nome del Tag è obbligatorio e non può superare i limiti.');
        }
        return $name;
    }

    private function requiredId(mixed $value): string
    {
        if (!is_string($value)) {
            throw new ApiException(422, 'invalid_request', 'tagId è obbligatorio.');
        }
        return $value;
    }

    private function assertId(string $id): void
    {
        if (!UuidV7::isValid($id)) {
            throw new ApiException(422, 'invalid_id', 'ID non valido.');
        }
    }

    /** @param array<string, mixed> $input @param list<string> $allowed */
    private function assertOnlyKeys(array $input, array $allowed): void
    {
        foreach (array_keys($input) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new ApiException(422, 'invalid_request', "Campo non supportato: {$key}.");
            }
        }
    }
}
