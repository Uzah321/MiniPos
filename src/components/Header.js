import React from 'react';

const Header = ({ user }) => {
  return (
    <header className="bg-red-600 text-white p-4 rounded-lg shadow-lg sticky top-0 z-50">
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-3xl font-bold">Chicken Inn POS</h1>
          <p className="text-sm opacity-90">Point of Sale System</p>
        </div>

        <div className="text-right">
          <div className="flex items-center gap-4">
            <div className="text-right">
              <p className="text-lg font-medium">BRANCH: SAMORA MACHEL</p>
              <p className="text-sm">CODE: ZWSAMMCHICKINN001</p>
            </div>
            <div className="h-10 w-px bg-white opacity-30"></div>
            <div className="text-right">
              <p className="text-lg font-medium">{user.name}</p>
              <p className="text-sm">{user.role} • {user.id}</p>
            </div>
          </div>
          <p className="text-xs mt-2 opacity-80">
            Logged in: {new Date().toLocaleDateString()} {new Date().toLocaleTimeString()}
          </p>
        </div>
      </div>
    </header>
  );
};

export default Header;
