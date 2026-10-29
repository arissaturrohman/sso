@if ($paginator->hasPages())
    <nav class="pagination-wrap" role="navigation" aria-label="Navigasi halaman">
        <p class="pagination-meta">
            Menampilkan <strong>{{ $paginator->firstItem() }}</strong>&ndash;<strong>{{ $paginator->lastItem() }}</strong>
            dari <strong>{{ $paginator->total() }}</strong> hasil
        </p>

        <ul class="pagination">
            @if ($paginator->onFirstPage())
                <li class="page-item disabled" aria-disabled="true">
                    <span class="page-link"><span aria-hidden="true">&lsaquo;</span><span class="pagination-label"> Sebelumnya</span></span>
                </li>
            @else
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Halaman sebelumnya">
                        <span aria-hidden="true">&lsaquo;</span><span class="pagination-label"> Sebelumnya</span>
                    </a>
                </li>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li class="page-item ellipsis" aria-hidden="true"><span class="page-link">{{ $element }}</span></li>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <li class="page-item active" aria-current="page"><span class="page-link">{{ $page }}</span></li>
                        @else
                            <li class="page-item"><a class="page-link" href="{{ $url }}">{{ $page }}</a></li>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Halaman berikutnya">
                        <span class="pagination-label">Berikutnya </span><span aria-hidden="true">&rsaquo;</span>
                    </a>
                </li>
            @else
                <li class="page-item disabled" aria-disabled="true">
                    <span class="page-link"><span class="pagination-label">Berikutnya </span><span aria-hidden="true">&rsaquo;</span></span>
                </li>
            @endif
        </ul>
    </nav>
@endif
