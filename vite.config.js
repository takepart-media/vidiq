import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";

export default defineConfig({
    base: "./",
    plugins: [
        laravel({
            input: ["resources/js/addon_cp.js"],
            publicDirectory: "resources/dist",
        }),
    ],
});
