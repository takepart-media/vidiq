<?php

namespace TakepartMedia\Vidiq\Fieldtypes;

use Statamic\Contracts\Assets\Asset;
use Statamic\Fields\Fieldtype;
use TakepartMedia\Vidiq\Vidiq;

/**
 * Read-only field that renders the 3Q player for a vidiq asset.
 *
 * Statamic 6 offers no extension point for the asset editor's preview column,
 * so the Vue component teleports itself into it. Add this field to the asset
 * container's blueprint to activate it.
 */
class VidiqPlayer extends Fieldtype
{
    protected static $handle = 'vidiq_player';

    protected $icon = 'video';

    /**
     * Resolve the player URL server-side; the Vue component only renders it.
     */
    public function preload(): array
    {
        $asset = $this->field?->parent();

        return [
            'player_url' => $asset instanceof Asset ? Vidiq::playerUrl($asset) : null,
        ];
    }
}
