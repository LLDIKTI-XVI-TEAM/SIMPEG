import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/pages/audit-log.js',
                'resources/js/pages/employee-import.js',
                'resources/js/pages/manual-external-approval.js',
                'resources/js/pages/employee-statistics.js',
                'resources/js/pages/dashboard-charts.js',
                'resources/js/pages/chain-batch-editor.js',
                'resources/js/pages/cuti-config-navigation.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
