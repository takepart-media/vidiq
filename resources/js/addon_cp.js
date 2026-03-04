/** vidiq CP addon – status strips on thumbnails + 3Q player iframe in asset editor. */

const STATUS_COLORS = {
    published: "#16a34a",
    unpublished: "#ca8a04",
    draft: "#94a3b8",
};

let assetDataFetch = null;

/** Set of container handles whose disk driver is 3q. Populated from /cp/vidiq/assets. */
let vidiqContainers = new Set();

/** path → { thumbnailUrl, status } */
const statusMap = {};

/** Player URL set by interceptor when an editor modal opens for a vidiq video. */
let pendingPlayerUrl = null;

function loadAssetData(axios) {
    if (assetDataFetch) {
        return assetDataFetch;
    }

    assetDataFetch = axios
        .get("/cp/vidiq/assets")
        .then((r) => {
            const data = r.data ?? {};
            vidiqContainers = new Set(Object.keys(data));
            return data;
        })
        .catch(() => {
            assetDataFetch = null;
            return {};
        });

    return assetDataFetch;
}

/** Overlay a coloured left strip on the thumbnail's direct parent. */
function injectStatusColor(imgEl, status) {
    const container = imgEl.parentElement;
    if (!container || container.querySelector(".vidiq-status-indicator")) {
        return;
    }

    const strip = document.createElement("div");
    strip.className = "vidiq-status-indicator";
    strip.style.cssText = [
        "position:absolute",
        "top:0",
        "left:-3px",
        "bottom:0",
        "width:6px",
        `background:${STATUS_COLORS[status] ?? STATUS_COLORS.draft}`,
        "pointer-events:none",
        "z-index:1",
        "border-radius:2px 0 0 2px",
    ].join(";");

    container.style.position = "relative";
    container.appendChild(strip);
}

/** Replace <video> in the asset editor with a 3Q player iframe using pendingPlayerUrl. */
function tryInjectVideoPlayers() {
    if (!pendingPlayerUrl) {
        return;
    }

    const video = document.querySelector(
        ".asset-editor.is-file .image-wrapper video[controls]",
    );
    if (!video || video.parentElement?.querySelector("iframe.vidiq-player")) {
        return;
    }

    const iframe = document.createElement("iframe");
    iframe.className = "vidiq-player";
    iframe.src = pendingPlayerUrl;
    iframe.style.cssText = "width:100%;height:100%;border:0;display:block";
    iframe.allow = "autoplay; fullscreen";
    video.replaceWith(iframe);
    pendingPlayerUrl = null;
}

/** Match img.asset-thumbnail src against statusMap and inject strips. */
function tryInjectStatusColors() {
    if (!Object.keys(statusMap).length) {
        return;
    }

    document.querySelectorAll("img.asset-thumbnail").forEach((img) => {
        const src = img.getAttribute("src");
        if (!src) {
            return;
        }

        for (const { thumbnailUrl, status } of Object.values(statusMap)) {
            if (src === thumbnailUrl) {
                injectStatusColor(img, status ?? "draft");
                break;
            }
        }
    });
}

/** On initial entry-form load, re-trigger loadAssets() for vidiq AssetRow instances missing thumbnails. */
function fixFieldtypeInitialLoad() {
    const fieldtypesToFix = new Set();

    document.querySelectorAll("tr").forEach((tr) => {
        const vm = tr.__vue__;
        if (!vm?.asset?.id) {
            return;
        }

        const colonPos = vm.asset.id.indexOf("::");
        if (colonPos < 0) {
            return;
        }

        const container = vm.asset.id.substring(0, colonPos);
        if (!vidiqContainers.has(container) || vm.asset.isImage) {
            return;
        }

        let parent = vm.$parent;
        while (parent && !parent.loadAssets) {
            parent = parent.$parent;
        }
        if (parent?.value?.length) {
            fieldtypesToFix.add(parent);
        }
    });

    fieldtypesToFix.forEach((ft) => ft.loadAssets(ft.value));
}

let fixTimer = null;

const domObserver = new MutationObserver(() => {
    tryInjectStatusColors();
    tryInjectVideoPlayers();
    clearTimeout(fixTimer);
    fixTimer = setTimeout(fixFieldtypeInitialLoad, 300);
});

Statamic.booted(() => {
    const axios = Vue.prototype.$axios;
    if (!axios) {
        return;
    }

    domObserver.observe(document.body, { childList: true, subtree: true });

    axios.interceptors.response.use(async (response) => {
        const url = response.config?.url ?? "";

        if (
            url.includes("/vidiq/assets") ||
            url.includes("/vidiq/player-url")
        ) {
            return response;
        }

        const assetData = await loadAssetData(axios);

        // Asset browser folder listing
        const folderMatch = url.match(/\/assets\/browse\/folders\/([^/?]+)/);
        if (folderMatch) {
            const container = folderMatch[1];
            if (!vidiqContainers.has(container)) {
                return response;
            }

            const assets = response.data?.data?.assets;
            if (!assets?.length) {
                return response;
            }

            response.data.data.assets = assets.map((asset) => {
                const data = assetData[container]?.[asset.path];
                if (!data) {
                    return asset;
                }

                statusMap[asset.path] = {
                    thumbnailUrl: data.thumbnail_url,
                    status: data.release_status,
                };

                if (!data.thumbnail_url) {
                    return asset;
                }

                return {
                    ...asset,
                    is_image: true,
                    thumbnail: data.thumbnail_url,
                };
            });

            Vue.nextTick(() => setTimeout(tryInjectStatusColors, 150));
            return response;
        }

        // Asset editor modal (/cp/assets/{base64-id}): fetch + store player URL
        if (url.match(/\/assets\/[^/]+$/) && response.data?.data?.id) {
            const asset = response.data.data;
            const colonPos = asset.id?.indexOf("::") ?? -1;
            const container =
                colonPos >= 0 ? asset.id.substring(0, colonPos) : null;

            if (container && vidiqContainers.has(container) && asset.isVideo) {
                try {
                    const r = await axios.get("/cp/vidiq/player-url", {
                        params: { path: asset.path, container },
                    });
                    pendingPlayerUrl = r.data?.player_url ?? null;
                } catch {
                    // ignore – video stays as-is
                }
            }

            return response;
        }

        // Assets fieldtype row display
        if (url.includes("/assets-fieldtype")) {
            const assets = Array.isArray(response.data) ? response.data : null;
            if (!assets?.length) {
                return response;
            }

            response.data = assets.map((asset) => {
                const colonPos = asset.id?.indexOf("::") ?? -1;
                const container =
                    colonPos >= 0 ? asset.id.substring(0, colonPos) : null;
                if (!container || !vidiqContainers.has(container)) {
                    return asset;
                }

                const data = assetData[container]?.[asset.path];
                if (!data?.thumbnail_url) {
                    return asset;
                }

                statusMap[asset.path] = {
                    thumbnailUrl: data.thumbnail_url,
                    status: data.release_status,
                };

                return {
                    ...asset,
                    isImage: true,
                    thumbnail: data.thumbnail_url,
                };
            });

            return response;
        }

        return response;
    });
});
