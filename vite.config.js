import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";

export default defineConfig({
    base: "./",
    plugins: [
        laravel({
            input: ["resources/js/addon_cp.js", "resources/css/addon_cp.css"],
            publicDirectory: "resources/dist",
        }),
    ],
});
