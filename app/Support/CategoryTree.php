<?php

namespace App\Support;

use Illuminate\Support\Collection;

final class CategoryTree
{
    /**
     * @param Collection<int, object> $categories
     * @return array<int, int>
     */
    public static function activeSubtreeIds(Collection $categories, int $rootId): array
    {
        $childrenByParent = $categories->groupBy(
            fn ($category) => (string) ($category->parent_id ?? 0)
        );
        $pending = [$rootId];
        $ids = [];

        while ($pending !== []) {
            $id = array_shift($pending);

            if (in_array($id, $ids, true)) {
                continue;
            }

            $ids[] = $id;

            foreach ($childrenByParent->get((string) $id, collect()) as $child) {
                $pending[] = (int) $child->id;
            }
        }

        return $ids;
    }

    /**
     * @param Collection<int, object> $categories
     * @return array<int, object>
     */
    public static function activeLineage(Collection $categories, ?int $categoryId): array
    {
        if ($categoryId === null) {
            return [];
        }

        $byId = $categories->keyBy('id');
        $lineage = [];
        $seen = [];
        $currentId = $categoryId;

        while ($currentId !== null && ! isset($seen[$currentId])) {
            $category = $byId->get($currentId);

            if ($category === null) {
                break;
            }

            $seen[$currentId] = true;
            array_unshift($lineage, $category);
            $currentId = $category->parent_id === null ? null : (int) $category->parent_id;
        }

        return $lineage;
    }
}
