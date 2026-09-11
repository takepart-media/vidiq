<?php

namespace TakepartMedia\Vidiq\Fieldtypes;

use Statamic\Fields\Fieldtype;

/**
 * Displays a 3Q release status as a coloured badge, in the asset listing and
 * in the asset editor. Read-only: the status is owned by 3Q.
 */
class VidiqStatus extends Fieldtype
{
    protected static $handle = 'vidiq_status';

    protected $icon = 'eye';

    /**
     * Resolve the badge payload for the asset browser's table column.
     */
    public function preProcessIndex($value): ?array
    {
        return $this->badge($value);
    }

    /**
     * Resolve the badge payload for the asset editor's publish form.
     */
    public function preProcess($value): ?array
    {
        return $this->badge($value);
    }

    /**
     * Store the plain status again, so saving an asset cannot corrupt it.
     */
    public function process($value): ?string
    {
        return is_array($value) ? ($value['value'] ?? null) : $value;
    }

    /**
     * Translate a 3Q release status into a label and a badge colour.
     */
    private function badge(?string $value): ?array
    {
        if (! $value) {
            return null;
        }

        return [
            'value' => $value,
            'label' => __('vidiq::messages.status_'.$value),
            'color' => match ($value) {
                'published' => 'green',
                'unpublished' => 'amber',
                default => 'default',
            },
        ];
    }
}
