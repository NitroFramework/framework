{{-- Shared benchmark page. Byte-identical on every framework under test.

     Deliberately limited to the directive subset every Blade implementation
     supports identically: @extends/@section/@yield, @foreach, @if, {{ }}.
     Nothing framework-specific, so the compiled output is the same shape and
     the measurement is of the render path, not of feature coverage. --}}
@extends('bench-layout')

@section('title', 'Benchmark')

@section('rowcount', count($rows))

@section('content')
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Name</th>
                <th>Email</th>
                <th>Status</th>
                <th>Score</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row['id'] }}</td>
                    <td>{{ $row['name'] }}</td>
                    <td>{{ $row['email'] }}</td>
                    <td>
                        @if ($row['active'])
                            <span class="badge badge-active">Active</span>
                        @else
                            <span class="badge badge-inactive">Inactive</span>
                        @endif
                    </td>
                    <td>{{ $row['score'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection
