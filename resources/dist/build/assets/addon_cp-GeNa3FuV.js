Statamic.component("vidiq_player-fieldtype",{props:["meta"],computed:{playerUrl(){var e;return((e=this.meta)==null?void 0:e.player_url)??null},teleportDisabled(){return!document.querySelector(".asset-editor .editor-preview")}},template:`
        <teleport to=".asset-editor .editor-preview" :disabled="teleportDisabled">
            <div class="vidiq-player" v-if="playerUrl">
                <iframe :src="playerUrl" allowfullscreen></iframe>
            </div>
        </teleport>
    `});const t={props:["value"],template:'<ui-badge v-if="value" :color="value.color" :text="value.label" pill />'};Statamic.component("vidiq_status-fieldtype",t);Statamic.component("vidiq_status-fieldtype-index",t);
