export const CUSTOMER_ROLE_ID = 3;

interface RoleLike {
  role?: string | null;
  role_id?: number | string | null;
}

export const isCustomerAccount = (user?: RoleLike | null): boolean => {
  if (!user) return false;

  return String(user.role || '').toLowerCase() === 'customer'
    || Number(user.role_id) === CUSTOMER_ROLE_ID;
};
