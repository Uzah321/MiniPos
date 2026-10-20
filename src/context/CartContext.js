import React, { createContext, useContext, useState } from 'react';

const CartContext = createContext();

export const useCart = () => useContext(CartContext);

export const CartProvider = ({ children }) => {
  const [cart, setCart] = useState([]);

  const addToCart = (item) => {
    setCart(prev => {
      const existing = prev.find(i => i.id === item.id);
      if (existing) {
        return prev.map(i =>
          i.id === item.id ? { ...i, quantity: i.quantity + 1 } : i
        );
      }
      return [...prev, { ...item, quantity: 1 }];
    });
  };

  const updateItemQuantity = (id, amount) => {
    setCart(prev =>
      prev
        .map(i =>
          i.id === id ? { ...i, quantity: Math.max(1, i.quantity + amount) } : i
        )
        .filter(i => i.quantity > 0)
    );
  };

  const removeItem = (id) => {
    setCart(prev => prev.filter(i => i.id !== id));
  };

  const clearCart = () => {
    setCart([]);
  };

  return (
    <CartContext.Provider value={{ cart, addToCart, updateItemQuantity, removeItem, clearCart }}>
      {children}
    </CartContext.Provider>
  );
};
