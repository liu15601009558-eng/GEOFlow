@extends('admin.layouts.app')

@section('title', __('admin.tracking.article_title', ['default' => '文章追踪详情']))

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <!-- 返回 -->
    <div class="mb-4">
        <a href="{{ route('admin.tracking.index') }}" class="text-blue-600 hover:underline">
            ← {{ __('admin.tracking.back', ['default' => '返回追踪总览']) }}
        </a>
    </div>

    <!-- 文章信息 -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h1 class="text-xl font-bold text-gray-900 mb-2">{{ $article->title }}</h1>
        <div class="text-sm text-gray-600 space-y-1">
            <p><strong>Slug:</strong> {{ $article->slug }}</p>
            <p><strong>关键词:</strong> {{ $article->original_keyword ?? '-' }}</p>
            <p><strong>状态:</strong> 
                <span class="px-2 py-1 rounded text-xs {{ $article->status === 'published' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                    {{ $article->status }}
                </span>
            </p>
            <p><strong>发布:</strong> {{ $article->published_at ? $article->published_at->format('Y-m-d H:i') : '-' }}</p>
        </div>
    </div>

    <!-- 各站点追踪状态 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-4 py-3 border-b border-gray-200">
            <h2 class="font-semibold text-gray-900">{{ __('admin.tracking.article_sites', ['default' => '各站点收录状态']) }}</h2>
        </div>
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-sm font-medium text-gray-600">{{ __('admin.tracking.table.site', ['default' => '站点']) }}</th>
                    <th class="px-4 py-2 text-center text-sm font-medium text-gray-600">{{ __('admin.tracking.table.status', ['default' => '收录状态']) }}</th>
                    <th class="px-4 py-2 text-center text-sm font-medium text-gray-600">{{ __('admin.tracking.table.checked', ['default' => '检查时间']) }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($tracking as $t)
                    <tr class="border-t">
                        <td class="px-4 py-3 text-sm">{{ $t->site_name }}</td>
                        <td class="px-4 py-3 text-center">
                            @if($t->is_indexed)
                                <span class="px-2 py-1 bg-green-100 text-green-800 text-xs rounded">{{ __('admin.tracking.status.indexed', ['default' => '已收录']) }}</span>
                            @else
                                <span class="px-2 py-1 bg-yellow-100 text-yellow-800 text-xs rounded">{{ __('admin.tracking.status.pending', ['default' => '待收录']) }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-gray-500">
                            {{ $t->last_checked_at ? \Carbon\Carbon::parse($t->last_checked_at)->format('Y-m-d H:i') : '-' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-4 py-8 text-center text-gray-500">
                            {{ __('admin.tracking.no_tracking', ['default' => '暂无追踪数据。文章发布后会自动开始追踪。']) }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
