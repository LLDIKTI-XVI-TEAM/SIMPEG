<div class="space-y-4 animate-pulse">
    <!-- Header Controls Skeleton -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-4">
        <div class="flex flex-wrap gap-2">
            <!-- Filter buttons skeletons -->
            <div class="h-10 w-24 bg-gray-200 dark:bg-gray-700 rounded-lg"></div>
            <div class="h-10 w-24 bg-gray-200 dark:bg-gray-700 rounded-lg"></div>
            <div class="h-10 w-24 bg-gray-200 dark:bg-gray-700 rounded-lg"></div>
        </div>
        <div class="flex gap-2 w-full md:w-auto">
            <!-- Search & Export buttons skeletons -->
            <div class="h-10 w-full md:w-64 bg-gray-200 dark:bg-gray-700 rounded-lg"></div>
            <div class="h-10 w-24 bg-gray-200 dark:bg-gray-700 rounded-lg"></div>
            <div class="h-10 w-24 bg-gray-200 dark:bg-gray-700 rounded-lg"></div>
        </div>
    </div>

    <!-- Table Skeleton -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                    <tr>
                        <th class="px-6 py-4"><div class="h-4 w-12 bg-gray-200 dark:bg-gray-600 rounded"></div></th>
                        <th class="px-6 py-4"><div class="h-4 w-32 bg-gray-200 dark:bg-gray-600 rounded"></div></th>
                        <th class="px-6 py-4"><div class="h-4 w-24 bg-gray-200 dark:bg-gray-600 rounded"></div></th>
                        <th class="px-6 py-4"><div class="h-4 w-24 bg-gray-200 dark:bg-gray-600 rounded"></div></th>
                        <th class="px-6 py-4"><div class="h-4 w-20 bg-gray-200 dark:bg-gray-600 rounded"></div></th>
                        <th class="px-6 py-4"><div class="h-4 w-16 bg-gray-200 dark:bg-gray-600 rounded"></div></th>
                        <th class="px-6 py-4 text-center"><div class="h-4 w-16 mx-auto bg-gray-200 dark:bg-gray-600 rounded"></div></th>
                    </tr>
                </thead>
                <tbody>
                    @for ($i = 0; $i < 10; $i++)
                        <tr class="border-b dark:border-gray-700">
                            <td class="px-6 py-4"><div class="h-10 w-10 bg-gray-200 dark:bg-gray-700 rounded-full"></div></td>
                            <td class="px-6 py-4">
                                <div class="flex flex-col gap-2">
                                    <div class="h-4 w-40 bg-gray-200 dark:bg-gray-700 rounded"></div>
                                    <div class="h-3 w-24 bg-gray-200 dark:bg-gray-700 rounded"></div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex flex-col gap-2">
                                    <div class="h-4 w-32 bg-gray-200 dark:bg-gray-700 rounded"></div>
                                    <div class="h-3 w-20 bg-gray-200 dark:bg-gray-700 rounded"></div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex flex-col gap-2">
                                    <div class="h-4 w-32 bg-gray-200 dark:bg-gray-700 rounded"></div>
                                    <div class="h-3 w-40 bg-gray-200 dark:bg-gray-700 rounded"></div>
                                </div>
                            </td>
                            <td class="px-6 py-4"><div class="h-6 w-16 bg-gray-200 dark:bg-gray-700 rounded-full"></div></td>
                            <td class="px-6 py-4"><div class="h-6 w-20 bg-gray-200 dark:bg-gray-700 rounded-full"></div></td>
                            <td class="px-6 py-4">
                                <div class="flex justify-center gap-2">
                                    <div class="h-8 w-8 bg-gray-200 dark:bg-gray-700 rounded"></div>
                                    <div class="h-8 w-8 bg-gray-200 dark:bg-gray-700 rounded"></div>
                                </div>
                            </td>
                        </tr>
                    @endfor
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-gray-200 dark:border-gray-700">
            <div class="flex justify-between items-center">
                <div class="h-4 w-32 bg-gray-200 dark:bg-gray-700 rounded"></div>
                <div class="flex gap-1">
                    <div class="h-8 w-8 bg-gray-200 dark:bg-gray-700 rounded"></div>
                    <div class="h-8 w-8 bg-gray-200 dark:bg-gray-700 rounded"></div>
                    <div class="h-8 w-8 bg-gray-200 dark:bg-gray-700 rounded"></div>
                </div>
            </div>
        </div>
    </div>
</div>
