<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ropa - Camila Viali</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;1,400&family=Inter:wght@300;400;500&display=swap" rel="stylesheet">
    <style> .font-serif { font-family: 'Playfair Display', serif; } .font-sans { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-white font-sans text-gray-800">

    <header class="border-b border-gray-200 py-6">
        <div class="max-w-7xl mx-auto px-4 flex justify-between items-center">
            <a href="/" class="text-2xl font-serif tracking-widest font-semibold uppercase">NOMBRE TIENDA</a>
            <nav class="hidden md:flex space-x-8 text-sm uppercase tracking-wider font-medium">
                <a href="/ropa" class="border-b border-black pb-1">Ropa</a>
                <a href="/#boutiques" class="hover:text-gray-500 transition">Boutiques</a>
            </nav>
            <div class="flex space-x-4">
                <svg class="w-5 h-5 cursor-pointer hover:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path></svg>
            </div>
        </div>
    </header>

    <div class="max-w-7xl mx-auto px-4 py-4 text-xs text-gray-400">
        <a href="/" class="hover:text-black">Inicio</a> <span class="mx-2">/</span> <span class="text-black">Ropa</span>
    </div>

    <main class="max-w-7xl mx-auto px-4 py-8 flex flex-col md:flex-row gap-10">
        
        <aside class="w-full md:w-1/4 space-y-6">
            <div class="border-b border-gray-200 pb-4">
                <button class="w-full flex justify-between items-center text-sm font-semibold tracking-widest uppercase">
                    Ordenar por
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </button>
            </div>
            
            <div class="border-b border-gray-200 pb-4">
                <button class="w-full flex justify-between items-center text-sm font-semibold tracking-widest uppercase">
                    Talla
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </button>
            </div>

            <div class="border-b border-gray-200 pb-4">
                <button class="w-full flex justify-between items-center text-sm font-semibold tracking-widest uppercase mb-4">
                    Categorías
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </button>
                <div class="space-y-2 pl-2">
                    @foreach($categories as $category)
                        <label class="flex items-center space-x-2 text-sm text-gray-600 cursor-pointer hover:text-black">
                            <input type="checkbox" class="accent-black">
                            <span>{{ $category->name }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        </aside>

        <section class="w-full md:w-3/4">
            <h1 class="text-2xl font-serif tracking-widest uppercase mb-8">ROPA</h1>
            
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @forelse($products as $product)
                <div class="group cursor-pointer">
                    <div class="relative aspect-[3/4] overflow-hidden bg-gray-100 mb-3">
                        @if($product->images->isNotEmpty())
                            <img src="{{ asset('storage/' . $product->images->first()->image_path) }}" alt="{{ $product->name }}" class="w-full h-full object-cover transition duration-700 group-hover:scale-105">
                        @else
                            <div class="w-full h-full flex items-center justify-center text-gray-400 text-sm">Sin foto</div>
                        @endif
                    </div>
                    <div class="text-center">
                        <h3 class="text-sm font-medium text-gray-900 uppercase tracking-wide">{{ $product->name }}</h3>
                        <p class="text-sm text-gray-500 mt-1">S/ {{ number_format($product->sale_price, 2) }}</p>
                    </div>
                </div>
                @empty
                    <p class="col-span-3 text-center py-10 text-gray-500">No hay prendas disponibles en este momento.</p>
                @endforelse
            </div>
        </section>
    </main>

</body>
</html>