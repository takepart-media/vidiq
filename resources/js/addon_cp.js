/**
 * Renders the 3Q player inside the Statamic asset editor.
 *
 * Statamic 6 exposes no slot, hook or store for the editor's preview column, so
 * the fieldtype component teleports itself in there and the stylesheet hides the
 * core <video> element (which has no usable src on a private container).
 *
 * ponytail: the teleport target is the first `.editor-preview` in the document —
 * wrong one if an asset editor is ever opened from inside another asset editor.
 * Upgrade path: target a per-editor selector once Statamic exposes one.
 */
Statamic.component("vidiq_player-fieldtype", {
    props: ["meta"],

    computed: {
        /** @returns {string|null} The 3Q player URL resolved by the fieldtype's preload(). */
        playerUrl() {
            return this.meta?.player_url ?? null;
        },

        /** @returns {boolean} True when used outside the asset editor, so we render inline instead. */
        teleportDisabled() {
            return !document.querySelector(".asset-editor .editor-preview");
        },
    },

    template: `
        <teleport to=".asset-editor .editor-preview" :disabled="teleportDisabled">
            <div class="vidiq-player" v-if="playerUrl">
                <iframe :src="playerUrl" allowfullscreen></iframe>
            </div>
        </teleport>
    `,
});

/** Renders a 3Q release status as a coloured badge. Payload comes from VidiqStatus. */
const statusBadge = {
    props: ["value"],
    template: `<ui-badge v-if="value" :color="value.color" :text="value.label" pill />`,
};

Statamic.component("vidiq_status-fieldtype", statusBadge);
Statamic.component("vidiq_status-fieldtype-index", statusBadge);
