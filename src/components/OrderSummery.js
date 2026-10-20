import React from 'react';

const OrderSummary = ({ cart, subtotal, tax, total, updateQuantity, removeFromCart, clearCart }) => (
  <div className="w-96 bg-white rounded-lg shadow-lg p-4 flex flex-col fixed right-4 top-[100px] bottom-4 overflow-y-auto">
    <div className="flex justify-between items-center mb-4">
      <h2 className="text-xl font-bold text-gray-800">ORDER SUMMARY</h2>
      <span className="bg-gray-200 text-gray-700 text-sm px-2 py-1 rounded">
        {cart.length} items
      </span>
    </div>

    <div className="flex-1 overflow-y-auto mb-4 space-y-2">
      {cart.length === 0 ? (
        <div className="text-center mt-10 text-gray-500">
          <div className="text-4xl mb-2">🛒</div>
          <p>No items in cart</p>
          <p className="text-sm">Click on items to add to cart</p>
        </div>
      ) : (
        cart.map((item) => (
          <div key={item.id} className="flex justify-between items-center border-b py-3">
            <div className="flex-1">
              <p className="font-semibold text-gray-800">{item.name}</p>
              <div className="flex items-center gap-2 mt-1">
                <button onClick={() => updateQuantity(item.id, item.quantity - 1)}
                  className="w-6 h-6 bg-gray-200 rounded-full flex items-center justify-center text-sm">-</button>
                <span className="text-sm text-gray-600">Qty: {item.quantity}</span>
                <button onClick={() => updateQuantity(item.id, item.quantity + 1)}
                  className="w-6 h-6 bg-gray-200 rounded-full flex items-center justify-center text-sm">+</button>
                <button onClick={() => removeFromCart(item.id)} className="text-red-500 text-xs ml-2">Remove</button>
              </div>
            </div>
            <div className="text-right">
              <span className="font-bold text-gray-800">N${item.total.toFixed(2)}</span>
              <p className="text-xs text-gray-500">N${item.price.toFixed(2)} ea</p>
            </div>
          </div>
        ))
      )}
    </div>

    <div className="mb-4 space-y-2 bg-gray-50 p-3 rounded-lg">
      <div className="flex justify-between text-sm"><span>Subtotal</span><span>N${subtotal.toFixed(2)}</span></div>
      <div className="flex justify-between text-sm"><span>Tax (10%)</span><span>N${tax.toFixed(2)}</span></div>
      <div className="flex justify-between font-bold text-lg border-t pt-2">
        <span>Total</span><span className="text-red-600">N${total.toFixed(2)}</span>
      </div>
    </div>

    <div className="space-y-2">
      <button className="w-full bg-red-600 text-white py-3 rounded-lg font-semibold hover:bg-red-700 transition-colors" disabled={cart.length === 0}>
        Process Payment - N${total.toFixed(2)}
      </button>
      <button onClick={clearCart} className="w-full bg-gray-300 text-gray-700 py-2 rounded-lg font-semibold hover:bg-gray-400" disabled={cart.length === 0}>
        Clear Order
      </button>
    </div>
  </div>
);

export default OrderSummary;
