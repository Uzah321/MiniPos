import React from "react";

export default function ReportViewNavigator({
  items = [],
  activeKey,
  onChange,
  title = "Report views",
  groups,
  className = "",
}) {
  const navigationGroups = Array.isArray(groups) && groups.length
    ? groups.filter((group) => Array.isArray(group?.items) && group.items.length)
    : [{ title, items, activeKey, onChange }];
  const itemCount = navigationGroups.reduce((count, group) => count + group.items.length, 0);

  if (itemCount < 2) return null;

  return (
    <aside className={`w-[250px] shrink-0 border-r border-[#D8DCDC] bg-white ${className}`}>
      <div className="h-full overflow-y-auto">
        {navigationGroups.map((group, groupIndex) => {
          const groupTitle = group.title || title;
          return (
            <React.Fragment key={`${groupTitle}-${groupIndex}`}>
              <div className="flex h-11 items-center border-b border-[#D8DCDC] bg-[#F7F8F9] px-4 text-[12px] font-semibold text-[#485353]">
                {groupTitle}
              </div>
              <nav aria-label={groupTitle} className="py-1">
                {group.items.map((item) => {
                  const key = item.key ?? item.id;
                  const active = key === group.activeKey;
                  return (
                    <button
                      key={key}
                      type="button"
                      onClick={() => group.onChange(key)}
                      className={`block w-full border-l-2 px-4 py-2.5 text-left ${
                        active
                          ? "border-[#069495] bg-[#E8F7F5] text-[#006869]"
                          : "border-transparent bg-white text-[#324443] hover:bg-[#F5F7FA]"
                      }`}
                    >
                      <span className="block text-[12px] font-semibold">{item.label}</span>
                      {item.description && (
                        <span className="mt-0.5 block text-[10px] font-normal text-[#627474]">
                          {item.description}
                        </span>
                      )}
                    </button>
                  );
                })}
              </nav>
            </React.Fragment>
          );
        })}
      </div>
    </aside>
  );
}
