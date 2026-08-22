@extends('admin.layouts.app')

@section('title', __('admin.tracking.title', ['default' => '发布效果追踪']))

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <!-- 标题 -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.tracking.title', ['default' => '发布效果追踪']) }}</h1>
        <p class="text-gray-600 mt-1">{{ __('admin.tracking.desc', ['default' => '监控文章收录状态与关键词排名']) }}</p>
    </div>

    <!-- 成功消息 -->
    @if(session('success'))
        <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
            <p class="text-green-800">{{ session('success') }}</p>
        </div>
    @endif

    <!-- 筛选 -->
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <form method="GET" class="flex gap-4 items-center">
            <label class="text-sm font-medium text-gray-700">{{ __('admin.tracking.filter_task', ['default' => '选择任务']) }}:</label>
            <select name="task_id" class="border rounded-md px-3 py-2 text-sm" onchange="this.form.submit()">
                <option value="">{{ __('admin.tracking.all_tasks', ['default' => '全部任务']) }}</option>
                @foreach($tasks as $task)
                    <option value="{{ $task->id }}" {{ $selectedTaskId == $task->id ? 'selected' : '' }}>
                        {{ $task->name }}
                    </option>
                @endforeach
            </select>
            <a href="{{ route('admin.tracking.index') }}" class="text-sm text-blue-600 hover:underline">
                {{ __('admin.tracking.reset', ['default' => '重置']) }}
            </a>
        </form>
    </div>

    <!-- 汇总卡片 -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-sm text-gray-600">{{ __('admin.tracking.card.total', ['default' => '追踪文章']) }}</div>
            <div class="text-2xl font-bold text-gray-900">{{ $report['total_tracked'] }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-sm text-gray-600">{{ __('admin.tracking.card.indexed', ['default' => '已收录']) }}</div>
            <div class="text-2xl font-bold text-green-600">{{ $report['indexed_count'] }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-sm text-gray-600">{{ __('admin.tracking.card.pending', ['default' => '未收录']) }}</div>
            <div class="text-2xl font-bold text-yellow-600">{{ $report['not_indexed_count'] }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-sm text-gray-600">{{ __('admin.tracking.card.rate', ['default' => '收录率']) }}</div>
            <div class="text-2xl font-bold {{ $report['indexed_rate'] >= 80 ? 'text-green-600' : ($report['indexed_rate'] >= 50 ? 'text-yellow-600' : 'text-red-600') }}">
                {{ $report['indexed_rate'] }}%
            </div>
        </div>
    </div>

    <!-- 操作按钮 -->
    <div class="flex gap-4 mb-6">
        <form method="POST" action="{{ route('admin.tracking.check-now') }}" class="inline">
            @csrf
            @if($selectedTaskId)
                <input type="hidden" name="task_id" value="{{ $selectedTaskId }}">
            @endif
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                📊 {{ __('admin.tracking.btn.check', ['default' => '立即检查收录']) }}
            </button>
        </form>
        <a href="{{ route('admin.tracking.api-summary', array_filter(['task_id' => $selectedTaskId])) }}" 
           class="bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700">
            🔗 {{ __('admin.tracking.btn.api', ['default' => 'API 数据']) }}
        </a>
    </div>

    <!-- 按站点统计 -->
    @if($report['by_site']->isNotEmpty())
        <div class="bg-white rounded-lg shadow mb-6">
            <div class="px-4 py-3 border-b border-gray-200">
                <h2 class="font-semibold text-gray-900">{{ __('admin.tracking.section.by_site', ['default' => '按站点统计']) }}</h2>
            </div>
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-sm font-medium text-gray-600">{{ __('admin.tracking.table.site', ['default' => '站点']) }}</th>
                        <th class="px-4 py-2 text-center text-sm font-medium text-gray-600">{{ __('admin.tracking.table.total', ['default' => '总数']) }}</th>
                        <th class="px-4 py-2 text-center text-sm font-medium text-gray-600">{{ __('admin.tracking.table.indexed', ['default' => '已收录']) }}</th>
                        <th class="px-4 py-2 text-center text-sm font-medium text-gray-600">{{ __('admin.tracking.table.rate', ['default' => '收录率']) }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($report['by_site'] as $site)
                        <tr class="border-t">
                            <td class="px-4 py-3 text-sm">{{ $site->site_name }}</td>
                            <td class="px-4 py-3 text-center text-sm">{{ $site->total }}</td>
                            <td class="px-4 py-3 text-center text-sm text-green-600">{{ $site->indexed }}</td>
                            <td class="px-4 py-3 text-center text-sm font-medium {{ $site->rate >= 80 ? 'text-green-600' : ($site->rate >= 50 ? 'text-yellow-600' : 'text-red-600') }}">
                                {{ $site->rate }}%
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <!-- 最近追踪记录 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-4 py-3 border-b border-gray-200 flex justify-between items-center">
            <h2 class="font-semibold text-gray-900">{{ __('admin.tracking.section.recent', ['default' => '最近追踪记录']) }}</h2>
            <span class="text-sm text-gray-500">{{ count($recentChecks) }} {{ __('admin.tracking.records', ['default' => '条记录']) }}</span>
        </div>
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-sm font-medium text-gray-600">{{ __('admin.tracking.table.article', ['default' => '文章']) }}</th>
                    <th class="px-4 py-2 text-left text-sm font-medium text-gray-600">{{ __('admin.tracking.table.site', ['default' => '站点']) }}</th>
                    <th class="px-4 py-2 text-center text-sm font-medium text-gray-600">{{ __('admin.tracking.table.status', ['default' => '收录状态']) }}</th>
                    <th class="px-4 py-2 text-center text-sm font-medium text-gray-600">{{ __('admin.tracking.table.checked', ['default' => '检查时间']) }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($recentChecks as $check)
                    <tr class="border-t hover:bg-gray-50">
                        <td class="px-4 py-3 text-sm">
                            <a href="{{ route('admin.tracking.show', $check->article_id) }}" class="text-blue-600 hover:underline">
                                {{ Str::limit($check->article_title, 40) }}
                            </a>
                        </td>
                        <td class="px-4 py-3 text-sm">{{ $check->site_name }}</td>
                        <td class="px-4 py-3 text-center">
                            @if($check->is_indexed)
                                <span class="px-2 py-1 bg-green-100 text-green-800 text-xs rounded">{{ __('admin.tracking.status.indexed', ['default' => '已收录']) }}</span>
                            @else
                                <span class="px-2 py-1 bg-yellow-100 text-yellow-800 text-xs rounded">{{ __('admin.tracking.status.pending', ['default' => '待收录']) }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-gray-500">
                            {{ $check->last_checked_at ? \Carbon\Carbon::parse($check->last_checked_at)->diffForHumans() : '-' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-gray-500">
                            {{ __('admin.tracking.no_records', ['default' => '暂无追踪记录。文章发布后会自动加入追踪队列。']) }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
