import React from 'react';

const ProductGrid = ({ products, addToCart }) => (
  <div className="grid grid-cols-3 gap-4 mt-4">
    {products.map((product) => (
      <div
        key={product.id}
        className={`border rounded-lg p-4 hover:shadow-md cursor-pointer transition-all duration-200 ${
          product.popular ? 'border-2 border-yellow-400' : 'border-gray-200'
        } ${product.bestseller ? 'bg-red-50' : 'bg-white'}`}
        onClick={() => addToCart(product)}
      >
        <div className="flex justify-between items-start mb-2">
          <h3 className="font-semibold text-gray-700 text-sm leading-tight">{product.name}</h3>
          {product.popular && (
            <span className="bg-red-500 text-white text-xs px-1 py-0.5 rounded">HOT</span>
          )}
          {product.bestseller && (
            <span className="bg-green-500 text-white text-xs px-1 py-0.5 rounded">BEST</span>
          )}
        </div>
        <div className="flex justify-between items-center mt-2">
          <span className="text-gray-600 font-bold">N${product.price.toFixed(2)}</span>
          <span className="bg-red-600 text-white text-xs px-2 py-1 rounded">Add +</span>
        </div>
      </div>
    ))}
  </div>
);

export default ProductGrid;
