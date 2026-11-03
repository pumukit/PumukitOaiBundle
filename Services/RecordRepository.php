<?php

declare(strict_types=1);

namespace Pumukit\OaiBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Query\Builder;
use Doctrine\ODM\MongoDB\Query\Expr;
use MongoDB\BSON\ObjectId;
use Pumukit\SchemaBundle\Document\EmbeddedBroadcast;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Series;

final class RecordRepository
{
    private const ID = '/^[a-f\d]{24}$/';

    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly OaiConfiguration $configuration,
    ) {}

    public static function isId(?string $id): bool
    {
        return null !== $id && 1 === preg_match(self::ID, $id);
    }

    public function find(?string $id): ?MultimediaObject
    {
        if (!self::isId($id)) {
            return null;
        }

        $record = $this->records()->field('_id')->equals(new ObjectId($id))->getQuery()->getSingleResult();

        return $record instanceof MultimediaObject ? $record : null;
    }

    public function page(ResumptionToken $token, int $limit): array
    {
        $queryBuilder = $this->window($token)->sort('_id', 1)->limit($limit);
        if ('' !== $token->after) {
            $queryBuilder->field('_id')->gt(new ObjectId($token->after));
        }

        return $queryBuilder->getQuery()->execute()->toArray();
    }

    public function count(ResumptionToken $token): int
    {
        return $this->window($token)->count()->getQuery()->execute();
    }

    public function earliestDatestamp(): ?\DateTimeInterface
    {
        $updated = $this->records()
            ->field('updatedAt')->notEqual(null)
            ->sort('updatedAt', 1)
            ->getQuery()->getSingleResult()
        ;
        $legacy = $this->records()
            ->field('updatedAt')->equals(null)
            ->sort('public_date', 1)
            ->getQuery()->getSingleResult()
        ;

        $dates = array_filter([
            $updated instanceof MultimediaObject ? $updated->isUpdatedAt() : null,
            $legacy instanceof MultimediaObject ? $legacy->getPublicDate() : null,
        ]);

        return [] === $dates ? null : min($dates);
    }

    public function series(?string $id): ?Series
    {
        if (!self::isId($id)) {
            return null;
        }

        $series = $this->seriesQuery()->field('_id')->equals(new ObjectId($id))->getQuery()->getSingleResult();

        return $series instanceof Series ? $series : null;
    }

    public function seriesPage(string $after, int $limit): array
    {
        $queryBuilder = $this->seriesQuery()->sort('_id', 1)->limit($limit);
        if ('' !== $after) {
            $queryBuilder->field('_id')->gt(new ObjectId($after));
        }

        return $queryBuilder->getQuery()->execute()->toArray();
    }

    public function seriesCount(): int
    {
        return $this->seriesQuery()->count()->getQuery()->execute();
    }

    private function window(ResumptionToken $token): Builder
    {
        $queryBuilder = $this->records();
        if (null !== $token->set) {
            $queryBuilder->field('series')->references($this->documentManager->getReference(Series::class, $token->set));
        }

        if (null !== $token->from || null !== $token->until) {
            $queryBuilder->addAnd(
                $queryBuilder->expr()->addOr(
                    $this->range($queryBuilder->expr()->field('updatedAt'), $token),
                    $this->range($queryBuilder->expr()->field('updatedAt')->equals(null)->field('public_date'), $token),
                )
            );
        }

        return $queryBuilder;
    }

    private function range(Expr $expr, ResumptionToken $token): Expr
    {
        if (null !== $token->from) {
            $expr->gte($token->from);
        }
        if (null !== $token->until) {
            $expr->lt($token->until);
        }

        return $expr;
    }

    private function records(): Builder
    {
        $display = ['tags' => $this->configuration->displayTrackTag, 'hide' => false];
        $queryBuilder = $this->documentManager->getRepository(MultimediaObject::class)->createStandardQueryBuilder();
        if ($this->configuration->listOnlyPublishedObjects) {
            $queryBuilder->field('status')->equals(MultimediaObject::STATUS_PUBLISHED);
        }

        return $queryBuilder
            ->field('embeddedBroadcast.type')->equals(EmbeddedBroadcast::TYPE_PUBLIC)
            ->field('tags.cod')->equals($this->configuration->pubChannelTag)
            ->addAnd(
                $queryBuilder->expr()->addOr(
                    $queryBuilder->expr()->field('tracks')->elemMatch($display)->field('properties.opencast')->exists(false),
                    $queryBuilder->expr()->field('properties.opencast')->exists(true),
                    $queryBuilder->expr()->field('external')->elemMatch($display),
                    $queryBuilder->expr()->field('documents')->elemMatch($display),
                    $queryBuilder->expr()->field('images')->elemMatch($display),
                )
            )
        ;
    }

    private function seriesQuery(): Builder
    {
        return $this->documentManager->createQueryBuilder(Series::class)->field('hide')->equals(false);
    }
}
